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

        $packages = [];
        foreach (IndependentPlanPackages::products() as $product) {
            foreach (IndependentPlanPackages::months() as $months) {
                $price = IndependentPlanPackages::priceBdt($product, $months);
                $packages[] = [
                    'product' => $product->value,
                    'months' => $months,
                    'base_amount_bdt' => $price,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => $price,
                ];
            }
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
                'available' => false,
                'amount_bdt' => 0,
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