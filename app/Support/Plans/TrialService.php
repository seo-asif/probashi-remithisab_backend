<?php

namespace App\Support\Plans;

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessSubscription;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TrialService
{
    public const TRIAL_DAYS = 7;

    public function activate(
        Business $business,
        BusinessMembership $membership,
    ): BusinessSubscription {
        if ((int) $membership->business_id !== (int) $business->id) {
            throw new LogicException(
                'Trial activation membership does not belong to the current business.'
            );
        }

        if (
            !in_array(
                $membership->role,
                [
                    BusinessRole::Owner,
                    BusinessRole::Administrator,
                ],
                true,
            )
        ) {
            throw new ApiException(
                'PLAN_TRIAL_FORBIDDEN',
                'Only the business owner or administrator can start the trial.',
                403,
            );
        }

        return DB::transaction(function () use (
            $business,
        ): BusinessSubscription {
            /*
             * Lock the stable business row first.
             *
             * This is the mutex for subscription changes. It prevents two
             * simultaneous requests from both activating the one-time trial.
             */
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->first();

            if ($lockedBusiness === null) {
                throw new LogicException(
                    'Current business disappeared during trial activation.'
                );
            }

            $subscription = BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->first();

            $now = new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC'),
            );

            if ($subscription?->trial_used_at !== null) {
                throw new ApiException(
                    'PLAN_TRIAL_ALREADY_USED',
                    'The free trial has already been used for this account.',
                    409,
                );
            }

            if (PlanResolver::isUnlimited($subscription, $now)) {
                throw new ApiException(
                    'PLAN_ALREADY_UNLIMITED',
                    'This account already has Unlimited access.',
                    409,
                );
            }

            $endsAt = $now->modify(
                '+' . self::TRIAL_DAYS . ' days'
            );

            if ($subscription === null) {
                $subscription = new BusinessSubscription([
                    'business_id' => $business->id,
                ]);
            }

            $subscription->forceFill([
                'tier' => PlanLimits::UNLIMITED,
                'access_source' => 'trial',
                'access_starts_at' => $now,
                'access_ends_at' => $endsAt,
                'trial_used_at' => $now,
            ]);

            $subscription->save();

            return $subscription->refresh();
        });
    }
}