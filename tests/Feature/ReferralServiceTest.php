<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Models\BusinessReferral;
use App\Models\BusinessSubscription;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class ReferralServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(
            ReferralService::class,
        );
    }

    public function test_server_referral_rules_match_product_decisions(): void
    {
        $this->assertSame(
            5,
            ReferralRules::MAX_QUALIFYING_REFERRALS,
        );

        $this->assertSame(
            7,
            ReferralRules::FREE_REWARD_DAYS,
        );

        $this->assertSame(
            150,
            ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        );
    }

    public function test_business_gets_one_permanent_server_generated_invite_code(): void
    {
        $business = $this->makeBusiness();

        $first = $this->service->profileFor(
            $business,
        );

        $second = $this->service->profileFor(
            $business,
        );

        $this->assertSame(
            $first->id,
            $second->id,
        );

        $this->assertSame(
            $first->invite_code,
            $second->invite_code,
        );

        $this->assertMatchesRegularExpression(
            '/\APRH-[A-HJ-NP-Z2-9]{8}\z/D',
            $first->invite_code,
        );

        $this->assertDatabaseCount(
            'business_referral_profiles',
            1,
        );

        $other = $this->service->profileFor(
            $this->makeBusiness(),
        );

        $this->assertNotSame(
            $first->invite_code,
            $other->invite_code,
        );
    }

    public function test_owner_can_claim_invite_and_receives_one_time_trial(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Inviter',
        ]);

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Friend',
        ]);

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $referral = $this->service->claim(
            $referred,
            $owner,
            '  ' . strtolower($profile->invite_code) . '  ',
        );

        $this->assertSame(
            $inviter->id,
            $referral->inviter_business_id,
        );

        $this->assertSame(
            $referred->id,
            $referral->referred_business_id,
        );

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNotNull(
            $referral->claimed_at,
        );

        $this->assertNotNull(
            $referral->referred_trial_granted_at,
        );

        /*
         * Claiming alone must never qualify a referral.
         */
        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertNull(
            $referral->qualified_at,
        );

        $this->assertNull(
            $referral->qualification_sequence,
        );

        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $referred->id,
            )
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'trial',
            $subscription->access_source,
        );

        $this->assertNotNull(
            $subscription->trial_used_at,
        );

        $this->assertSame(
            ReferralRules::FREE_REWARD_DAYS
                * 24
                * 60
                * 60,
            $subscription->access_ends_at->getTimestamp()
                - $subscription->access_starts_at->getTimestamp(),
        );

        /*
         * The inviter earns nothing merely from a claim.
         */
        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_administrator_can_claim_invite(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $administrator = $this->makeMember(
            $referred,
            BusinessRole::Administrator,
        );

        $referral = $this->service->claim(
            $referred,
            $administrator,
            $profile->invite_code,
        );

        $this->assertSame(
            $referred->id,
            $referral->referred_business_id,
        );
    }

    public function test_operator_cannot_claim_invite(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $operator = $this->makeMember(
            $referred,
            BusinessRole::Operator,
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $referred,
                $operator,
                $profile->invite_code,
            ),
            'REFERRAL_CLAIM_FORBIDDEN',
            403,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $referred->id,
            ],
        );
    }

    public function test_suspended_membership_cannot_claim_invite(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
            [
                'status' => AccountStatus::Suspended,
            ],
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $referred,
                $owner,
                $profile->invite_code,
            ),
            'REFERRAL_CLAIM_FORBIDDEN',
            403,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );
    }

    public function test_business_cannot_use_its_own_invite_code(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $profile = $this->service->profileFor(
            $business,
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $business,
                $owner,
                $profile->invite_code,
            ),
            'REFERRAL_SELF_NOT_ALLOWED',
            409,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        /*
         * A failed self-referral must not consume the one-time trial.
         */
        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
            ],
        );
    }

    public function test_repeating_same_claim_is_idempotent_and_does_not_restart_trial(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $first = $this->service->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $referred->id,
            )
            ->firstOrFail();

        $originalTrialUsedAt =
            $subscription->trial_used_at->getTimestamp();

        $originalEndsAt =
            $subscription->access_ends_at->getTimestamp();

        $second = $this->service->claim(
            $referred,
            $owner,
            strtolower($profile->invite_code),
        );

        $subscription->refresh();

        $this->assertSame(
            $first->id,
            $second->id,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            1,
        );

        $this->assertSame(
            $originalTrialUsedAt,
            $subscription->trial_used_at->getTimestamp(),
        );

        $this->assertSame(
            $originalEndsAt,
            $subscription->access_ends_at->getTimestamp(),
        );
    }

    public function test_business_cannot_change_to_another_inviter(): void
    {
        $inviterA = $this->makeBusiness([
            'name' => 'Inviter A',
        ]);

        $inviterB = $this->makeBusiness([
            'name' => 'Inviter B',
        ]);

        $profileA = $this->service->profileFor(
            $inviterA,
        );

        $profileB = $this->service->profileFor(
            $inviterB,
        );

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $first = $this->service->claim(
            $referred,
            $owner,
            $profileA->invite_code,
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $referred,
                $owner,
                $profileB->invite_code,
            ),
            'REFERRAL_ALREADY_CLAIMED',
            409,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            1,
        );

        $stored = BusinessReferral::query()
            ->where(
                'referred_business_id',
                $referred->id,
            )
            ->firstOrFail();

        $this->assertSame(
            $first->id,
            $stored->id,
        );

        $this->assertSame(
            $inviterA->id,
            $stored->inviter_business_id,
        );
    }

    public function test_invalid_and_unknown_invite_codes_are_rejected(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $business,
                $owner,
                'NOT-A-CODE',
            ),
            'REFERRAL_CODE_INVALID',
            422,
        );

        $this->assertApiException(
            fn () => $this->service->claim(
                $business,
                $owner,
                'PRH-22222222',
            ),
            'REFERRAL_CODE_NOT_FOUND',
            404,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );
    }

    public function test_referral_does_not_grant_second_trial_if_trial_was_already_used(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $usedAt = now('UTC')->subDays(20);
        $endsAt = now('UTC')->subDays(13);

        $subscription = BusinessSubscription::create([
            'business_id' => $referred->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'trial',
            'access_starts_at' => $usedAt,
            'access_ends_at' => $endsAt,
            'trial_used_at' => $usedAt,
        ]);

        $originalEnd =
            $subscription->access_ends_at->getTimestamp();

        $referral = $this->service->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        $subscription->refresh();

        $this->assertNull(
            $referral->referred_trial_granted_at,
        );

        $this->assertSame(
            $originalEnd,
            $subscription->access_ends_at->getTimestamp(),
        );

        $this->assertSame(
            $usedAt->getTimestamp(),
            $subscription->trial_used_at->getTimestamp(),
        );
    }

    public function test_active_paid_unlimited_is_not_overwritten_by_referral_trial(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->service->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $startsAt = now('UTC')->subDay();
        $endsAt = now('UTC')->addDays(30);

        $subscription = BusinessSubscription::create([
            'business_id' => $referred->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'paid',
            'access_starts_at' => $startsAt,
            'access_ends_at' => $endsAt,
            'trial_used_at' => null,
        ]);

        $referral = $this->service->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        $subscription->refresh();

        $this->assertNull(
            $referral->referred_trial_granted_at,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertSame(
            $endsAt->getTimestamp(),
            $subscription->access_ends_at->getTimestamp(),
        );

        $this->assertNull(
            $subscription->trial_used_at,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    private function assertApiException(
        callable $callback,
        string $code,
        int $status,
    ): ApiException {
        try {
            $callback();
        } catch (ApiException $e) {
            $this->assertSame(
                $code,
                $e->errorCode,
            );

            $this->assertSame(
                $status,
                $e->status,
            );

            return $e;
        }

        $this->fail(
            "Expected ApiException {$code} was not thrown."
        );
    }
}