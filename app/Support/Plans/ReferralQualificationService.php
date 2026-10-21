<?php

namespace App\Support\Plans;

use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessReferral;
use App\Models\BusinessSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\ReferralReward;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReferralQualificationService
{
    /**
     * Qualify a previously claimed referral after the referred
     * business has a confirmed, server-applied paid Unlimited payment.
     *
     * No referral is a normal no-op.
     *
     * The maximum-five rule is also a normal no-op: the paid
     * Unlimited purchase remains valid, but no sixth referral reward
     * is created.
     */
    public function qualifyApprovedUnlimitedPayment(
        PlanPaymentRequest $request,
    ): ?BusinessReferral {
        return DB::transaction(function () use (
            $request,
        ): ?BusinessReferral {
            $payment = PlanPaymentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertQualifyingPayment($payment);

            $referral = BusinessReferral::query()
                ->where(
                    'referred_business_id',
                    $payment->business_id,
                )
                ->lockForUpdate()
                ->first();

            /*
             * Buying Unlimited without having claimed an invite is
             * perfectly valid. There is simply no referral to reward.
             */
            if ($referral === null) {
                return null;
            }

            /*
             * A friend can qualify only once.
             *
             * A later paid renewal must never generate another reward
             * for the same referred business.
             */
            if ($referral->status === ReferralStatus::Qualified) {
                $this->assertQualifiedReferralState(
                    $referral,
                );

                return $referral->refresh();
            }

            if ($referral->status !== ReferralStatus::Claimed) {
                throw $this->stateInvalid(
                    'The referral has an invalid qualification status.'
                );
            }

            /*
             * No retroactive rewards.
             *
             * The invite relationship must already exist when the
             * platform administrator approves the paid Unlimited
             * payment.
             *
             * A referral may therefore be claimed while a payment is
             * still pending, but not after that payment was approved.
             */
            if (
                $referral->claimed_at === null
                || $payment->decided_at === null
                || $referral->claimed_at->getTimestamp()
                    > $payment->decided_at->getTimestamp()
            ) {
                return $referral->refresh();
            }

            /*
             * The inviter business row is the stable mutex for:
             *
             * - the lifetime five-referral limit;
             * - qualification sequence assignment;
             * - subscription reward mutation.
             */
            $inviter = Business::query()
                ->whereKey($referral->inviter_business_id)
                ->lockForUpdate()
                ->first();

            if ($inviter === null) {
                throw new LogicException(
                    'Inviter business disappeared during referral qualification.'
                );
            }

            $qualifiedCount = BusinessReferral::query()
                ->where(
                    'inviter_business_id',
                    $inviter->id,
                )
                ->where(
                    'status',
                    ReferralStatus::Qualified->value,
                )
                ->count();

            /*
             * Maximum five qualifying friends for the lifetime of
             * this inviter account.
             *
             * Do not fail the friend's legitimate paid activation.
             */
            if (
                $qualifiedCount
                >= ReferralRules::MAX_QUALIFYING_REFERRALS
            ) {
                return $referral->refresh();
            }

            $lastSequence = BusinessReferral::query()
                ->where(
                    'inviter_business_id',
                    $inviter->id,
                )
                ->whereNotNull(
                    'qualification_sequence',
                )
                ->max('qualification_sequence');

            $sequence = ((int) $lastSequence) + 1;

            if (
                $sequence < 1
                || $sequence
                    > ReferralRules::MAX_QUALIFYING_REFERRALS
            ) {
                throw $this->stateInvalid(
                    'The referral qualification sequence is invalid.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $subscription = BusinessSubscription::query()
                ->where(
                    'business_id',
                    $inviter->id,
                )
                ->lockForUpdate()
                ->first();

            /*
             * Only ACTIVE paid Unlimited counts as the paid underlying
             * plan for the 150 BDT reward.
             *
             * Trial/referral/other temporary Unlimited remains
             * underlying Free and therefore receives +7 days.
             */
            $paidInviter =
                $subscription !== null
                && $subscription->access_source === 'paid'
                && PlanResolver::isUnlimited(
                    $subscription,
                    $now,
                );

            if ($paidInviter) {
                $reward = $this->createRenewalDiscountReward(
                    $referral,
                    $inviter,
                );
            } else {
                $this->applyUnlimitedDays(
                    $inviter,
                    $subscription,
                    $now,
                );

                $reward = $this->createUnlimitedDaysReward(
                    $referral,
                    $inviter,
                    $now,
                );
            }

            /*
             * Reward creation and referral qualification occur in the
             * same database transaction.
             *
             * If either operation fails, neither is committed.
             */
            $referral->forceFill([
                'status' =>
                    ReferralStatus::Qualified,

                'qualifying_payment_request_id' =>
                    $payment->id,

                'qualified_at' =>
                    $now,

                'qualification_sequence' =>
                    $sequence,
            ])->save();

            /*
             * Defense in depth against an impossible partial state.
             */
            if (
                (int) $reward->referral_id
                !== (int) $referral->id
            ) {
                throw $this->stateInvalid(
                    'The referral reward does not belong to the qualifying referral.'
                );
            }

            return $referral->refresh();
        });
    }

    private function assertQualifyingPayment(
        PlanPaymentRequest $payment,
    ): void {
        if (
            $payment->product_type
                !== PlanPaymentProduct::Unlimited
            || $payment->status
                !== PlanPaymentStatus::Approved
            || $payment->decided_by_platform_admin_id === null
            || $payment->decided_at === null
            || $payment->applied_at === null
            || $payment->beneficiary_user_id !== null
        ) {
            throw new ApiException(
                'REFERRAL_PAYMENT_NOT_QUALIFYING',
                'Only an approved and applied paid Unlimited payment can qualify a referral.',
                409,
            );
        }

        /*
         * Do not trust an Approved flag alone.
         *
         * The paid Unlimited entitlement must actually exist and be
         * active for the referred business.
         */
        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $payment->business_id,
            )
            ->first();

        if (
            $subscription === null
            || $subscription->access_source !== 'paid'
            || !PlanResolver::isUnlimited(
                $subscription,
                $payment->applied_at,
            )
        ) {
            throw new ApiException(
                'REFERRAL_PAYMENT_NOT_QUALIFYING',
                'The approved payment does not have an applied paid Unlimited entitlement.',
                409,
            );
        }
    }

    private function createRenewalDiscountReward(
        BusinessReferral $referral,
        Business $inviter,
    ): ReferralReward {
        return ReferralReward::create([
            'referral_id' =>
                $referral->id,

            'beneficiary_business_id' =>
                $inviter->id,

            'reward_type' =>
                ReferralRewardType::RenewalDiscountBdt,

            'unlimited_days' =>
                null,

            'discount_bdt' =>
                ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);
    }

    private function createUnlimitedDaysReward(
        BusinessReferral $referral,
        Business $inviter,
        CarbonImmutable $appliedAt,
    ): ReferralReward {
        $reward = ReferralReward::create([
            'referral_id' =>
                $referral->id,

            'beneficiary_business_id' =>
                $inviter->id,

            'reward_type' =>
                ReferralRewardType::UnlimitedDays,

            'unlimited_days' =>
                ReferralRules::FREE_REWARD_DAYS,

            'discount_bdt' =>
                null,
        ]);

        /*
         * +7-day rewards are applied immediately.
         */
        $reward->forceFill([
            'status' =>
                ReferralRewardStatus::Applied,

            'applied_at' =>
                $appliedAt,
        ])->save();

        return $reward->refresh();
    }

    private function applyUnlimitedDays(
        Business $inviter,
        ?BusinessSubscription $subscription,
        CarbonImmutable $now,
    ): void {
        $days = ReferralRules::FREE_REWARD_DAYS;

        /*
         * Active non-paid Unlimited:
         *
         * Add the full seven days to the existing expiry instead of
         * replacing the remaining temporary access.
         *
         * Example:
         * 3 temporary days remaining + reward = 10 days remaining.
         */
        if (
            $subscription !== null
            && PlanResolver::isUnlimited(
                $subscription,
                $now,
            )
        ) {
            /*
             * An active paid subscription should have been routed to
             * the 150 BDT reward before reaching this method.
             */
            if ($subscription->access_source === 'paid') {
                throw $this->stateInvalid(
                    'Paid Unlimited was routed to the wrong referral reward.'
                );
            }

            /*
             * A null expiry represents indefinite Unlimited.
             * Never shorten such access merely to apply a reward.
             */
            if ($subscription->access_ends_at === null) {
                return;
            }

            $subscription->forceFill([
                'access_ends_at' =>
                    CarbonImmutable::instance(
                        $subscription->access_ends_at,
                    )->addDays($days),
            ])->save();

            return;
        }

        /*
         * Missing, Free or expired subscription:
         * start a seven-day referral entitlement now.
         *
         * Never clear trial_used_at. Referral rewards must not restore
         * eligibility for another one-time trial.
         */
        if ($subscription === null) {
            $subscription = new BusinessSubscription([
                'business_id' => $inviter->id,
            ]);
        }

        $subscription->forceFill([
            'tier' =>
                PlanLimits::UNLIMITED,

            'access_source' =>
                'referral',

            'access_starts_at' =>
                $now,

            'access_ends_at' =>
                $now->addDays($days),
        ])->save();
    }

    private function assertQualifiedReferralState(
        BusinessReferral $referral,
    ): void {
        if (
            $referral->qualifying_payment_request_id === null
            || $referral->qualified_at === null
            || $referral->qualification_sequence === null
            || $referral->qualification_sequence < 1
            || $referral->qualification_sequence
                > ReferralRules::MAX_QUALIFYING_REFERRALS
        ) {
            throw $this->stateInvalid(
                'The qualified referral has incomplete qualification state.'
            );
        }

        if (
            !ReferralReward::query()
                ->where(
                    'referral_id',
                    $referral->id,
                )
                ->exists()
        ) {
            throw $this->stateInvalid(
                'The qualified referral does not have its reward.'
            );
        }
    }

    private function stateInvalid(
        string $message,
    ): ApiException {
        return new ApiException(
            'REFERRAL_STATE_INVALID',
            $message,
            409,
        );
    }
}