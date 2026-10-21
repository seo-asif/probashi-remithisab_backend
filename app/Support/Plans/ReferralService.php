<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessReferral;
use App\Models\BusinessReferralProfile;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReferralService
{
    private const CODE_PREFIX = 'PRH-';

    /*
     * Avoid visually ambiguous characters:
     * I, O, 0 and 1 are deliberately excluded.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    private const CODE_GENERATION_ATTEMPTS = 5;

    public function __construct(
        private TrialService $trialService,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * Return the permanent server-generated invite profile for a business.
     *
     * Calling this repeatedly is idempotent.
     */
    public function profileFor(
        Business $business,
    ): BusinessReferralProfile {
        return DB::transaction(function () use (
            $business,
        ): BusinessReferralProfile {
            /*
             * Stable per-business mutex.
             *
             * Two devices asking for the invite code at the same time
             * must never create two profiles.
             */
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->first();

            if ($lockedBusiness === null) {
                throw new LogicException(
                    'Business disappeared while creating referral profile.'
                );
            }

            $existing = BusinessReferralProfile::query()
                ->where('business_id', $lockedBusiness->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            /*
             * invite_code also has a database UNIQUE constraint.
             *
             * insertOrIgnore lets us safely retry the astronomically
             * unlikely case where two businesses generate the same code
             * concurrently.
             */
            for (
                $attempt = 0;
                $attempt < self::CODE_GENERATION_ATTEMPTS;
                $attempt++
            ) {
                $code = $this->generateInviteCode();
                $now = now('UTC');

                $inserted = DB::table(
                    'business_referral_profiles'
                )->insertOrIgnore([
                    'business_id' => $lockedBusiness->id,
                    'invite_code' => $code,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

                if ($inserted === 1) {
                    return BusinessReferralProfile::query()
                        ->where(
                            'business_id',
                            $lockedBusiness->id,
                        )
                        ->firstOrFail();
                }

                /*
                 * Defense in depth in case another trusted caller
                 * created the business profile concurrently.
                 */
                $existing = BusinessReferralProfile::query()
                    ->where(
                        'business_id',
                        $lockedBusiness->id,
                    )
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new LogicException(
                'Could not generate a unique referral invite code.'
            );
        });
    }

    /**
     * Claim an inviter for the current business.
     *
     * This only establishes the relationship.
     *
     * It does NOT qualify the referral and does NOT create an inviter
     * reward. Qualification happens later after a new paid Unlimited
     * payment is approved.
     */
    public function claim(
        Business $referredBusiness,
        User $claimedBy,
        string $inviteCode,
    ): BusinessReferral {
        $inviteCode = $this->normalizeInviteCode(
            $inviteCode,
        );

        if (!$this->isValidInviteCode($inviteCode)) {
            throw new ApiException(
                'REFERRAL_CODE_INVALID',
                'Enter a valid invite code.',
                422,
            );
        }

        return DB::transaction(function () use (
            $referredBusiness,
            $claimedBy,
            $inviteCode,
        ): BusinessReferral {
            /*
             * Stable referred-business mutex.
             *
             * This serializes competing claims from multiple devices
             * and prevents the business from acquiring two inviters.
             */
            $lockedBusiness = Business::query()
                ->whereKey($referredBusiness->id)
                ->lockForUpdate()
                ->first();

            if ($lockedBusiness === null) {
                throw new LogicException(
                    'Current business disappeared during referral claim.'
                );
            }

/*
 * Do not rely only on business.context middleware.
 *
 * The business row has been reloaded and locked inside this
 * transaction, so its status is authoritative. Direct service
 * calls must not allow suspended businesses to claim referrals.
 */
            if ($lockedBusiness->status !== AccountStatus::Active) {
                throw $this->claimForbidden();
            }

            $membership = $this->claimingMembership(
                $lockedBusiness,
                $claimedBy,
            );

            $profile = BusinessReferralProfile::query()
                ->where('invite_code', $inviteCode)
                ->first();

            if ($profile === null) {
                throw new ApiException(
                    'REFERRAL_CODE_NOT_FOUND',
                    'This invite code was not found.',
                    404,
                );
            }

            if (
                (int) $profile->business_id
                === (int) $lockedBusiness->id
            ) {
                throw new ApiException(
                    'REFERRAL_SELF_NOT_ALLOWED',
                    'You cannot use your own invite code.',
                    409,
                );
            }

            /*
             * One referred business may have exactly one inviter.
             *
             * Retrying the same claim is idempotent.
             * Trying to switch to another inviter is forbidden.
             */
            $existing = BusinessReferral::query()
                ->where(
                    'referred_business_id',
                    $lockedBusiness->id,
                )
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->inviter_business_id
                    === (int) $profile->business_id
                ) {
                    return $existing->refresh();
                }

                throw new ApiException(
                    'REFERRAL_ALREADY_CLAIMED',
                    'This account already has an inviter.',
                    409,
                );
            }

            $referral = BusinessReferral::create([
                'inviter_business_id'  => $profile->business_id,
                'referred_business_id' => $lockedBusiness->id,
                'claimed_at'           => CarbonImmutable::now('UTC'),
            ]);

            /*
             * The friend's referral trial is the normal one-time
             * seven-day trial.
             *
             * If the account already used its trial, or currently has
             * Unlimited access, the referral remains valid but no second
             * trial is created.
             */
            $this->grantTrialIfEligible(
                $referral,
                $lockedBusiness,
                $membership,
            );

/*
 * Audit only the actual creation of the referral.
 *
 * The earlier same-inviter idempotent return does not reach this
 * point, so retrying the same claim cannot create duplicate events.
 *
 * Never store the invite code in audit metadata.
 */
            $this->auditLogger->record(
                'referral.claimed',
                $claimedBy,
                $lockedBusiness->id,
                $referral,
            );

            return $referral->refresh();
        });
    }

    /**
     * Normalize user-entered invite codes without making loose or
     * ambiguous inputs silently valid.
     */
    public function normalizeInviteCode(
        string $value,
    ): string {
        return strtoupper(trim($value));
    }

    private function claimingMembership(
        Business $business,
        User $user,
    ): BusinessMembership {
        /*
         * Do not trust the User model instance supplied by the caller.
         *
         * ReferralService can be invoked directly without HTTP middleware,
         * so reload and lock authoritative user state inside this transaction.
         */
        $eligibleUser = User::query()
            ->whereKey($user->id)
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
            throw $this->claimForbidden();
        }

        /*
         * The claimant must hold an active membership in this exact
         * business and must be an Owner or Administrator.
         */
        $membership = BusinessMembership::query()
            ->where(
                'business_id',
                $business->id,
            )
            ->where(
                'user_id',
                $eligibleUser->id,
            )
            ->where(
                'status',
                AccountStatus::Active->value,
            )
            ->lockForUpdate()
            ->first();

        if (
            $membership === null
            || !in_array(
                $membership->role,
                [
                    BusinessRole::Owner,
                    BusinessRole::Administrator,
                ],
                true,
            )
        ) {
            throw $this->claimForbidden();
        }

        return $membership;
    }

    private function grantTrialIfEligible(
        BusinessReferral $referral,
        Business $business,
        BusinessMembership $membership,
    ): void {
        try {
            $subscription = $this->trialService->activate(
                $business,
                $membership,
            );
        } catch (ApiException $e) {
            /*
             * These are normal referral situations:
             *
             * - the friend already used the one-time trial; or
             * - the friend already has active Unlimited.
             *
             * Neither situation invalidates the referral itself.
             */
            if (
                in_array(
                    $e->errorCode,
                    [
                        'PLAN_TRIAL_ALREADY_USED',
                        'PLAN_ALREADY_UNLIMITED',
                    ],
                    true,
                )
            ) {
                return;
            }

            throw $e;
        }

        $referral->forceFill([
            'referred_trial_granted_at' =>
            $subscription->trial_used_at ?? CarbonImmutable::now('UTC'),
        ]);

        $referral->save();
    }

    private function isValidInviteCode(
        string $code,
    ): bool {
        return preg_match(
            '/\APRH-[A-HJ-NP-Z2-9]{8}\z/D',
            $code,
        ) === 1;
    }

    private function generateInviteCode(): string
    {
        $code = '';

        $maxIndex = strlen(
            self::CODE_ALPHABET,
        ) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[
                random_int(0, $maxIndex)
            ];
        }

        return self::CODE_PREFIX . $code;
    }

    private function claimForbidden(): ApiException
    {
        return new ApiException(
            'REFERRAL_CLAIM_FORBIDDEN',
            'Only the account owner or administrator can use an invite code.',
            403,
        );
    }
}
