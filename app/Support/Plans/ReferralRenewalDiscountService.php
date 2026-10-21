<?php

namespace App\Support\Plans;

use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\ReferralReward;

final class ReferralRenewalDiscountService
{
    /**
     * Reserve at most one available referral discount for one
     * newly-created Unlimited renewal request.
     *
     * Caller must already hold the beneficiary business row lock.
     *
     * Payment creation and reward reservation must run inside the
     * same database transaction.
     */
    public function reserveForPayment(
        Business $business,
        PlanPaymentRequest $payment,
    ): PlanPaymentRequest {
        $this->assertReservablePayment(
            $business,
            $payment,
        );

        /*
         * Idempotency protection.
         *
         * Normally a brand-new payment cannot already have a reward,
         * but keeping this safe prevents a retry from reserving a
         * second reward for the same payment.
         */
        $existing = ReferralReward::query()
            ->where(
                'reserved_for_payment_request_id',
                $payment->id,
            )
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertValidReservation(
                $business,
                $payment,
                $existing,
            );

            return $payment->refresh();
        }

        /*
         * Oldest earned reward is consumed first.
         *
         * lockForUpdate prevents the same reward from being reserved
         * by two requests concurrently.
         */
        $reward = ReferralReward::query()
            ->where(
                'beneficiary_business_id',
                $business->id,
            )
            ->where(
                'reward_type',
                ReferralRewardType::RenewalDiscountBdt->value,
            )
            ->where(
                'status',
                ReferralRewardStatus::Available->value,
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($reward === null) {
            return $payment->refresh();
        }

        $this->assertAvailableReward(
            $business,
            $reward,
        );

        $basePrice = PlanPackages::priceBdt(
            $payment->package_months,
        );

        $discount = $reward->discount_bdt;

        /*
         * Current referral contract:
         *
         * exactly 150 BDT per qualifying paid referral.
         *
         * Never trust a malformed database reward to change the
         * server-authoritative payment amount.
         */
        if (
            $discount
            !== ReferralRules::PAID_RENEWAL_DISCOUNT_BDT
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount has an invalid amount.',
            );
        }

        $discountedPrice = $basePrice - $discount;

        if ($discountedPrice <= 0) {
            throw $this->invalidRewardState(
                'Referral renewal discount would create an invalid payment amount.',
            );
        }

        /*
         * Payment and reward are changed inside the caller's
         * transaction. If either save fails, both changes roll back.
         */
        $payment->forceFill([
            'expected_amount_bdt' => $discountedPrice,
        ])->save();

        $reservedAt = now('UTC');

        $reward->forceFill([
            'status' =>
                ReferralRewardStatus::Reserved,

            'reserved_for_payment_request_id' =>
                $payment->id,

            'reserved_at' =>
                $reservedAt,

            'applied_to_payment_request_id' =>
                null,

            'applied_at' =>
                null,
        ])->save();

        return $payment->refresh();
    }

    /**
     * Validate the payment amount against authoritative server state.
     *
     * A full-price payment is valid only when no referral reward is
     * reserved for it.
     *
     * A discounted payment is valid only when a valid 150 BDT reward
     * is reserved for this exact payment and business.
     */
    public function assertAuthoritativePaymentAmount(
        Business $business,
        PlanPaymentRequest $payment,
    ): void {
        if (
            $payment->product_type
            !== PlanPaymentProduct::Unlimited
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount validation requires an Unlimited payment.',
            );
        }

        if (
            (int) $payment->business_id
            !== (int) $business->id
        ) {
            throw $this->invalidRewardState(
                'Referral reward beneficiary does not match the payment business.',
            );
        }

        $months = $payment->package_months;

        if (
            $months === null
            || !PlanPackages::isValidMonths($months)
        ) {
            throw new ApiException(
                'PLAN_PAYMENT_PACKAGE_INVALID',
                'The stored payment package is invalid.',
                409,
            );
        }

        $basePrice = PlanPackages::priceBdt($months);

        /*
         * The reservation itself is the authorization for the
         * 150 BDT discount.
         *
         * Never trust expected_amount_bdt by itself.
         */
        $reward = ReferralReward::query()
            ->where(
                'reserved_for_payment_request_id',
                $payment->id,
            )
            ->lockForUpdate()
            ->first();

        /*
         * No reservation means this payment must contain the normal
         * server-authoritative package price.
         */
        if ($reward === null) {
            if (
                $payment->expected_amount_bdt
                !== $basePrice
            ) {
                throw new ApiException(
                    'PLAN_PAYMENT_AMOUNT_MISMATCH',
                    'The stored payment amount does not match the server-authoritative amount.',
                    409,
                );
            }

            return;
        }

        /*
         * A reservation exists.
         *
         * It must belong to this business and this exact payment,
         * and it must contain the authoritative 150 BDT reward.
         */
        $this->assertValidReservation(
            $business,
            $payment,
            $reward,
        );
    }

    /**
     * Consume a reserved renewal discount after successful approval.
     *
     * Reservation fields are deliberately retained as audit history.
     */
    public function applyReservedForApprovedPayment(
        Business $business,
        PlanPaymentRequest $payment,
    ): void {
        if (
            $payment->status
            !== PlanPaymentStatus::Approved
            || $payment->applied_at === null
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount can only be applied to an approved payment.',
            );
        }

        if (
            (int) $payment->business_id
            !== (int) $business->id
        ) {
            throw $this->invalidRewardState(
                'Referral reward beneficiary does not match the payment business.',
            );
        }

        $reward = ReferralReward::query()
            ->where(
                'reserved_for_payment_request_id',
                $payment->id,
            )
            ->lockForUpdate()
            ->first();

        /*
         * Full-price payments legitimately have no reservation.
         */
        if ($reward === null) {
            return;
        }

        $this->assertValidReservation(
            $business,
            $payment,
            $reward,
        );

        /*
         * Preserve:
         *
         * reserved_for_payment_request_id
         * reserved_at
         *
         * They form part of the permanent reward audit trail.
         */
        $reward->forceFill([
            'status' =>
                ReferralRewardStatus::Applied,

            'applied_to_payment_request_id' =>
                $payment->id,

            'applied_at' =>
                $payment->applied_at,
        ])->save();
    }

    /**
     * Release a reserved renewal discount when its payment is rejected.
     *
     * The reward becomes available for a future new renewal request.
     */
    public function releaseReservedForRejectedPayment(
        Business $business,
        PlanPaymentRequest $payment,
    ): void {
        if (
            $payment->status
            !== PlanPaymentStatus::Rejected
            || $payment->applied_at !== null
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount can only be released from a rejected payment.',
            );
        }

        if (
            (int) $payment->business_id
            !== (int) $business->id
        ) {
            throw $this->invalidRewardState(
                'Referral reward beneficiary does not match the payment business.',
            );
        }

        $reward = ReferralReward::query()
            ->where(
                'reserved_for_payment_request_id',
                $payment->id,
            )
            ->lockForUpdate()
            ->first();

        /*
         * A normal full-price rejected payment has no reward to
         * release.
         */
        if ($reward === null) {
            return;
        }

        $this->assertValidReservation(
            $business,
            $payment,
            $reward,
        );

        /*
         * Rejection does not consume the reward.
         *
         * Remove reservation lifecycle data so this reward can be
         * reserved by a future payment.
         */
        $reward->forceFill([
            'status' =>
                ReferralRewardStatus::Available,

            'reserved_for_payment_request_id' =>
                null,

            'reserved_at' =>
                null,

            'applied_to_payment_request_id' =>
                null,

            'applied_at' =>
                null,
        ])->save();
    }

    /**
     * Validate a newly-created payment before attempting to reserve
     * a referral reward for it.
     */
    private function assertReservablePayment(
        Business $business,
        PlanPaymentRequest $payment,
    ): void {
        if (
            (int) $payment->business_id
            !== (int) $business->id
        ) {
            throw $this->invalidRewardState(
                'Referral reward beneficiary does not match the payment business.',
            );
        }

        if (
            $payment->product_type
            !== PlanPaymentProduct::Unlimited
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discounts can only be used for Unlimited payments.',
            );
        }

        if (
            $payment->status
            !== PlanPaymentStatus::Pending
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discounts can only be reserved for pending payments.',
            );
        }

        if (
            !PlanPackages::isValidMonths(
                $payment->package_months,
            )
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount payment has an invalid package.',
            );
        }

        /*
         * Reservation begins from the normal server package price.
         *
         * This prevents a caller from supplying an already-reduced
         * or otherwise manipulated amount before reward reservation.
         */
        if (
            $payment->expected_amount_bdt
            !== PlanPackages::priceBdt(
                $payment->package_months,
            )
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount payment does not contain the server package price.',
            );
        }

        if (
            $payment->decided_by_platform_admin_id !== null
            || $payment->decision_reason !== null
            || $payment->decided_at !== null
            || $payment->applied_at !== null
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount cannot be reserved for a decided payment.',
            );
        }
    }

    /**
     * Validate an Available reward before reservation.
     */
    private function assertAvailableReward(
        Business $business,
        ReferralReward $reward,
    ): void {
        if (
            (int) $reward->beneficiary_business_id
            !== (int) $business->id
        ) {
            throw $this->invalidRewardState(
                'Referral reward belongs to another business.',
            );
        }

        if (
            $reward->reward_type
            !== ReferralRewardType::RenewalDiscountBdt
        ) {
            throw $this->invalidRewardState(
                'Referral reward has an invalid reward type.',
            );
        }

        if (
            $reward->status
            !== ReferralRewardStatus::Available
        ) {
            throw $this->invalidRewardState(
                'Referral reward is not available.',
            );
        }

        if (
            $reward->reserved_for_payment_request_id !== null
            || $reward->reserved_at !== null
            || $reward->applied_to_payment_request_id !== null
            || $reward->applied_at !== null
        ) {
            throw $this->invalidRewardState(
                'Available referral reward contains lifecycle data.',
            );
        }

        if (
            $reward->unlimited_days !== null
            || $reward->discount_bdt
            !== ReferralRules::PAID_RENEWAL_DISCOUNT_BDT
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount contains invalid reward data.',
            );
        }
    }

    /**
     * Validate an existing Reserved reward.
     *
     * The reservation itself is security-sensitive because it is the
     * server-side authorization for reducing the payment amount.
     */
    private function assertValidReservation(
        Business $business,
        PlanPaymentRequest $payment,
        ReferralReward $reward,
    ): void {
        if (
            (int) $payment->business_id
            !== (int) $business->id
            || (int) $reward->beneficiary_business_id
            !== (int) $business->id
            || $reward->reward_type
            !== ReferralRewardType::RenewalDiscountBdt
            || $reward->status
            !== ReferralRewardStatus::Reserved
            || (int) $reward->reserved_for_payment_request_id
            !== (int) $payment->id
            || $reward->reserved_at === null
            || $reward->applied_to_payment_request_id !== null
            || $reward->applied_at !== null
            || $reward->unlimited_days !== null
            || $reward->discount_bdt
            !== ReferralRules::PAID_RENEWAL_DISCOUNT_BDT
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount reservation is inconsistent.',
            );
        }

        $months = $payment->package_months;

        if (
            $months === null
            || !PlanPackages::isValidMonths($months)
        ) {
            throw $this->invalidRewardState(
                'Referral renewal discount payment has an invalid package.',
            );
        }

        $expectedAmount =
            PlanPackages::priceBdt($months)
            - ReferralRules::PAID_RENEWAL_DISCOUNT_BDT;

        if ($expectedAmount <= 0) {
            throw $this->invalidRewardState(
                'Referral renewal discount would create an invalid payment amount.',
            );
        }

        if (
            $payment->expected_amount_bdt
            !== $expectedAmount
        ) {
            throw $this->invalidRewardState(
                'Reserved referral discount does not match the payment amount.',
            );
        }
    }

    private function invalidRewardState(
        string $message,
    ): ApiException {
        return new ApiException(
            'REFERRAL_REWARD_STATE_INVALID',
            $message,
            409,
        );
    }
}