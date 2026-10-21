<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessSubscription;
use App\Models\User;
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

        return DB::transaction(function () use (
            $business,
            $membership,
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

            /*
             * The business row has been reloaded and locked inside this
             * transaction, so its status is authoritative.
             *
             * Direct service calls must not allow suspended businesses
             * to activate trial access.
             */
            if ($lockedBusiness->status !== AccountStatus::Active) {
                throw new ApiException(
                    'PLAN_TRIAL_FORBIDDEN',
                    'Trial access cannot be activated for a suspended business.',
                    403,
                );
            }

            $subscription = BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->first();

            /*
             * Do not trust the BusinessMembership model supplied by the caller.
             *
             * TrialService may be invoked directly without HTTP middleware, so
             * reload and lock the authoritative membership inside this transaction.
             */
            $lockedMembership = BusinessMembership::query()
                ->whereKey($membership->id)
                ->where(
                    'business_id',
                    $lockedBusiness->id,
                )
                ->where(
                    'user_id',
                    $membership->user_id,
                )
                ->where(
                    'status',
                    AccountStatus::Active->value,
                )
                ->lockForUpdate()
                ->first();

            if (
                $lockedMembership === null
                || !in_array(
                    $lockedMembership->role,
                    [
                        BusinessRole::Owner,
                        BusinessRole::Administrator,
                    ],
                    true,
                )
            ) {
                throw new ApiException(
                    'PLAN_TRIAL_FORBIDDEN',
                    'Only an active business owner or administrator can start the trial.',
                    403,
                );
            }

            /*
             * An active membership is not sufficient by itself.
             *
             * Revalidate the underlying user from authoritative database state
             * so suspended or unverified accounts cannot activate a trial through
             * a direct service call.
             */
            $eligibleUser = User::query()
                ->whereKey($lockedMembership->user_id)
                ->where(
                    'status',
                    AccountStatus::Active->value,
                )
                ->whereNotNull(
                    'email_verified_at',
                )
                ->lockForUpdate()
                ->first();

            if ($eligibleUser === null) {
                throw new ApiException(
                    'PLAN_TRIAL_FORBIDDEN',
                    'Only an active and verified account owner or administrator can start the trial.',
                    403,
                );
            }
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
                'tier'             => PlanLimits::UNLIMITED,
                'access_source'    => 'trial',
                'access_starts_at' => $now,
                'access_ends_at'   => $endsAt,
                'trial_used_at'    => $now,
            ]);

            $subscription->save();

            return $subscription->refresh();
        });
    }
}
