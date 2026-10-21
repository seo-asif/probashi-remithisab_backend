<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\ReferralStatus;
use App\Models\BusinessReferral;
use App\Models\BusinessReferralProfile;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class ReferralApiTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->referrals = app(
            ReferralService::class,
        );
    }

    public function test_referral_endpoints_require_authentication(): void
    {
        $this->getJson(
            '/api/v1/referrals',
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                'PRH-ABCDEFGH',
            ],
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );
    }

    public function test_owner_can_get_permanent_server_invite_code(): void
    {
        $business = $this->makeBusiness();

        $this->actAs(
            $business,
            BusinessRole::Owner,
        );

        $first = $this->getJson(
            '/api/v1/referrals',
        )
            ->assertOk()
            ->assertJsonPath(
                'success',
                true,
            )
            ->assertJsonPath(
                'data.referrals.claimed_count',
                0,
            )
            ->assertJsonPath(
                'data.referrals.qualified_count',
                0,
            )
            ->assertJsonPath(
                'data.referrals.maximum_qualifying',
                ReferralRules::MAX_QUALIFYING_REFERRALS,
            )
            ->assertJsonPath(
                'data.rewards.unlimited_rewards_earned',
                0,
            )
            ->assertJsonPath(
                'data.rewards.unlimited_days_earned',
                0,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_bdt_each',
                ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_rewards_earned',
                0,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_available',
                0,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_reserved',
                0,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_applied',
                0,
            )
            ->assertJsonPath(
                'data.claimed_referral',
                null,
            );

        $inviteCode = $first->json(
            'data.invite_code',
        );

        $this->assertIsString(
            $inviteCode,
        );

        $this->assertMatchesRegularExpression(
            '/\APRH-[A-HJ-NP-Z2-9]{8}\z/D',
            $inviteCode,
        );

        /*
         * Asking again must return the same permanent
         * server-generated code.
         */
        $second = $this->getJson(
            '/api/v1/referrals',
        )
            ->assertOk();

        $this->assertSame(
            $inviteCode,
            $second->json(
                'data.invite_code',
            ),
        );

        $this->assertSame(
            1,
            BusinessReferralProfile::query()
                ->where(
                    'business_id',
                    $business->id,
                )
                ->count(),
        );
    }

    public function test_administrator_can_view_referral_information(): void
    {
        $business = $this->makeBusiness();

        $this->actAs(
            $business,
            BusinessRole::Administrator,
        );

        $this->getJson(
            '/api/v1/referrals',
        )
            ->assertOk()
            ->assertJsonPath(
                'data.referrals.maximum_qualifying',
                ReferralRules::MAX_QUALIFYING_REFERRALS,
            )
            ->assertJsonPath(
                'data.rewards.renewal_discount_bdt_each',
                150,
            );
    }

    public function test_operator_cannot_view_or_claim_referrals(): void
    {
        $business = $this->makeBusiness();

        $this->actAs(
            $business,
            BusinessRole::Operator,
        );

        $this->getJson(
            '/api/v1/referrals',
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'REFERRAL_FORBIDDEN',
            );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                'PRH-ABCDEFGH',
            ],
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'REFERRAL_FORBIDDEN',
            );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );
    }

    public function test_owner_can_claim_invite_through_api_and_claim_does_not_create_reward(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Inviter',
        ]);

        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Referred Friend',
        ]);

        $this->actAs(
            $referred,
            BusinessRole::Owner,
        );

        /*
         * HTTP input may contain surrounding spaces and
         * lowercase characters. ReferralService owns
         * normalization.
         */
        $response = $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                '  '
                . strtolower(
                    $profile->invite_code,
                )
                . '  ',
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Invite code applied.',
            )
            ->assertJsonPath(
                'data.referral.status',
                'claimed',
            );

        $publicReferralId = $response->json(
            'data.referral.id',
        );

        $this->assertIsString(
            $publicReferralId,
        );

        $referral = BusinessReferral::query()
            ->where(
                'referred_business_id',
                $referred->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertSame(
            $referral->uuid,
            $publicReferralId,
        );

        $this->assertSame(
            $inviter->id,
            $referral->inviter_business_id,
        );

        /*
         * Claiming only establishes the relationship.
         *
         * It must never qualify the referral or create
         * an inviter reward.
         */
        $this->assertNull(
            $referral
                ->qualifying_payment_request_id,
        );

        $this->assertNull(
            $referral->qualified_at,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );

        /*
         * The GET endpoint now reports that this account
         * has already claimed an inviter, without exposing
         * the inviter's internal business ID.
         */
        $this->getJson(
            '/api/v1/referrals',
        )
            ->assertOk()
            ->assertJsonPath(
                'data.claimed_referral.id',
                $referral->uuid,
            )
            ->assertJsonPath(
                'data.claimed_referral.status',
                'claimed',
            )
            ->assertJsonMissingPath(
                'data.claimed_referral.inviter_business_id',
            );
    }

    public function test_invalid_invite_code_is_rejected_without_creating_referral(): void
    {
        $business = $this->makeBusiness();

        $this->actAs(
            $business,
            BusinessRole::Owner,
        );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                'not-a-valid-code',
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'REFERRAL_CODE_INVALID',
            );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_business_cannot_claim_its_own_invite_code(): void
    {
        $business = $this->makeBusiness();

        $profile = $this->referrals->profileFor(
            $business,
        );

        $this->actAs(
            $business,
            BusinessRole::Owner,
        );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                $profile->invite_code,
            ],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'code',
                'REFERRAL_SELF_NOT_ALLOWED',
            );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_business_cannot_switch_to_different_inviter(): void
    {
        $firstInviter = $this->makeBusiness([
            'name' => 'First Inviter',
        ]);

        $secondInviter = $this->makeBusiness([
            'name' => 'Second Inviter',
        ]);

        $firstProfile =
        $this->referrals->profileFor(
            $firstInviter,
        );

        $secondProfile =
        $this->referrals->profileFor(
            $secondInviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Referred Business',
        ]);

        $this->actAs(
            $referred,
            BusinessRole::Owner,
        );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                $firstProfile->invite_code,
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.referral.status',
                'claimed',
            );

        $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                $secondProfile->invite_code,
            ],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'code',
                'REFERRAL_ALREADY_CLAIMED',
            );

        $referral = BusinessReferral::query()
            ->where(
                'referred_business_id',
                $referred->id,
            )
            ->firstOrFail();

        $this->assertSame(
            $firstInviter->id,
            $referral->inviter_business_id,
        );

        $this->assertDatabaseCount(
            'business_referrals',
            1,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_same_invite_claim_is_http_idempotent(): void
    {
        $inviter = $this->makeBusiness();

        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness();

        $this->actAs(
            $referred,
            BusinessRole::Owner,
        );

        $first = $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                $profile->invite_code,
            ],
        )
            ->assertOk();

        $second = $this->postJson(
            '/api/v1/referrals/claim',
            [
                'invite_code' =>
                $profile->invite_code,
            ],
        )
            ->assertOk();

        $this->assertSame(
            $first->json(
                'data.referral.id',
            ),
            $second->json(
                'data.referral.id',
            ),
        );

        $this->assertDatabaseCount(
            'business_referrals',
            1,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }
    public function test_client_cannot_tamper_with_referral_claim_state(): void
{
    $realInviter = $this->makeBusiness([
        'name' => 'Real Inviter',
    ]);

    $fakeInviter = $this->makeBusiness([
        'name' => 'Fake Inviter',
    ]);

    $profile = $this->referrals->profileFor(
        $realInviter,
    );

    $referred = $this->makeBusiness([
        'name' => 'Referred Business',
    ]);

    $this->actAs(
        $referred,
        BusinessRole::Owner,
    );

    $this->postJson(
        '/api/v1/referrals/claim',
        [
            'invite_code' =>
                $profile->invite_code,

            /*
             * Malicious/untrusted fields.
             *
             * The server must ignore all of these and derive
             * referral state from authenticated context and the
             * authoritative invite-code record.
             */
            'inviter_business_id' =>
                $fakeInviter->id,

            'referred_business_id' =>
                $fakeInviter->id,

            'status' =>
                'qualified',

            'qualifying_payment_request_id' =>
                999999,

            'qualification_sequence' =>
                1,

            'qualified_at' =>
                now()->toISOString(),
        ],
    )
        ->assertOk()
        ->assertJsonPath(
            'data.referral.status',
            'claimed',
        )
        ->assertJsonMissingPath(
            'data.referral.inviter_business_id',
        )
        ->assertJsonMissingPath(
            'data.referral.referred_business_id',
        );

    $referral = BusinessReferral::query()
        ->where(
            'referred_business_id',
            $referred->id,
        )
        ->sole();

    $this->assertSame(
        $realInviter->id,
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

    $this->assertNull(
        $referral->qualifying_payment_request_id,
    );

    $this->assertNull(
        $referral->qualification_sequence,
    );

    $this->assertNull(
        $referral->qualified_at,
    );

    $this->assertDatabaseCount(
        'referral_rewards',
        0,
    );
}

    public function test_missing_invite_code_uses_standard_validation_response(): void
    {
        $business = $this->makeBusiness();

        $this->actAs(
            $business,
            BusinessRole::Owner,
        );

        $this->postJson(
            '/api/v1/referrals/claim',
            [],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );
    }
}
