<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Models\BusinessSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PaidAccessService
{
    public function activate(
        Business $business,
        int $months,
    ): BusinessSubscription {
        if (!PlanPackages::isValidMonths($months)) {
            throw new InvalidArgumentException(
                'Unsupported Unlimited package duration.'
            );
        }

        return DB::transaction(function () use (
            $business,
            $months,
        ): BusinessSubscription {
            /*
             * All subscription mutations use the stable business row
             * as their mutex.
             */
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->first();

            if ($lockedBusiness === null) {
                throw new LogicException(
                    'Business disappeared during Unlimited activation.'
                );
            }

            $subscription = BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->first();

            $now = CarbonImmutable::now('UTC');

            /*
             * Active PAID Unlimited renewals extend from the current
             * paid expiry.
             *
             * Trial, Free and expired access starts paid Unlimited
             * from the current server time.
             */
            $extendExistingPaidAccess =
                $subscription !== null
                && $subscription->tier === PlanLimits::UNLIMITED
                && $subscription->access_source === 'paid'
                && $subscription->access_ends_at !== null
                && $subscription->access_ends_at->isAfter($now);

            if ($extendExistingPaidAccess) {
                $startsAt = $subscription->access_starts_at ?? $now;

                $endsAt = CarbonImmutable::instance(
                    $subscription->access_ends_at
                )->addMonthsNoOverflow($months);
            } else {
                $startsAt = $now;
                $endsAt = $now->addMonthsNoOverflow($months);
            }

            if ($subscription === null) {
                $subscription = new BusinessSubscription([
                    'business_id' => $business->id,
                ]);
            }

            /*
             * Never clear trial_used_at.
             *
             * Buying Unlimited must not restore eligibility for
             * another one-time trial.
             */
            $subscription->forceFill([
                'tier' => PlanLimits::UNLIMITED,
                'access_source' => 'paid',
                'access_starts_at' => $startsAt,
                'access_ends_at' => $endsAt,
            ]);

            $subscription->save();

            return $subscription->refresh();
        });
    }
}