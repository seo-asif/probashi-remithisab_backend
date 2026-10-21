<?php

namespace App\Support\Plans;

use App\Enums\PlanPaymentProvider;
use App\Enums\PlanPaymentStatus;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformPaymentProviderSetting;
use App\Models\ReferralReward;

final class PaymentOptionsService
{
    public function forBusiness(Business $business): array
    {
        $provider = PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                PlanPaymentProvider::ManualBkash->value,
            )
            ->first();

        $bkashNumber = $provider?->public_account_identifier;

        $providerAvailable =
            $provider !== null
            && $provider->is_enabled
            && $bkashNumber !== null
            && BangladeshMobileNumber::isValid($bkashNumber);

        /*
         * Preview only.
         *
         * We deliberately do not reserve a referral reward here.
         * GET /payments/options must never mutate reward state.
         *
         * The actual reservation remains inside
         * PaymentRequestService::createUnlimited().
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
            ->first();

        $discount = 0;

        if (
            $reward !== null
            && $reward->discount_bdt
                === ReferralRules::PAID_RENEWAL_DISCOUNT_BDT
        ) {
            $discount =
                ReferralRules::PAID_RENEWAL_DISCOUNT_BDT;
        }

        $packages = [];

        foreach (PlanPackages::months() as $months) {
            $baseAmount = PlanPackages::priceBdt($months);

            /*
             * This is only the amount the user would currently expect
             * to pay if they submit this package next.
             *
             * PaymentRequestService recalculates and reserves the
             * authoritative reward during POST /payments.
             */
            $payableAmount = max(
                0,
                $baseAmount - $discount,
            );

            $packages[] = [
                'months'             => $months,
                'base_amount_bdt'    => $baseAmount,
                'discount_bdt'       => $discount,
                'payable_amount_bdt' => $payableAmount,
            ];
        }

        $pendingCount = PlanPaymentRequest::query()
            ->where('business_id', $business->id)
            ->where(
                'status',
                PlanPaymentStatus::Pending->value,
            )
            ->count();

        return [
            'payment_method' => [
                'provider' =>
                    PlanPaymentProvider::ManualBkash->value,

                'available' => $providerAvailable,

                /*
                 * Do not give Flutter a destination number when
                 * manual bKash payments are disabled.
                 */
                'bkash_number' =>
                    $providerAvailable
                        ? $bkashNumber
                        : null,
            ],

            'packages' => $packages,

            'referral_discount' => [
                'available' => $discount > 0,
                'amount_bdt' => $discount,
            ],

            'pending_requests' => [
                'count' => $pendingCount,
                'limit' => PaymentRequestService::MAX_PENDING,

                /*
                 * This controls whether the UI should offer a new
                 * manual bKash payment.
                 *
                 * POST /payments still independently enforces the
                 * pending limit.
                 */
                'can_submit' =>
                    $providerAvailable
                    && $pendingCount
                        < PaymentRequestService::MAX_PENDING,
            ],
        ];
    }
}