<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class SupportVerificationApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_verified_user_can_request_support_code(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/support/verification-code'
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath(
                'data.expires_in_seconds',
                600
            );

        $code = $response->json('data.verification_code');

        $this->assertMatchesRegularExpression(
            '/^[0-9]{6}$/',
            (string) $code
        );
    }

    public function test_support_code_is_not_stored_in_plaintext(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $code = (string) $response->json(
            'data.verification_code'
        );

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'support_verification',
        ]);

        $this->assertDatabaseMissing('otp_codes', [
            'user_id'   => $user->id,
            'code_hash' => $code,
        ]);
    }

    public function test_support_code_cannot_be_requested_again_during_cooldown(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/support/verification-code')
            ->assertOk();

        $this->postJson('/api/v1/support/verification-code')
            ->assertStatus(429)
            ->assertJsonPath(
                'code',
                'TOO_MANY_REQUESTS'
            );
    }

    public function test_new_support_code_invalidates_previous_unused_code(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $firstResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $firstCode = (string) $firstResponse->json(
            'data.verification_code'
        );

        $this->travel(61)->seconds();

        $secondResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $secondCode = (string) $secondResponse->json(
            'data.verification_code'
        );

        $this->assertNotSame(
            $firstCode,
            $secondCode
        );

        $rows = \App\Models\OtpCode::query()
            ->where('user_id', $user->id)
            ->where(
                'purpose',
                \App\Models\OtpCode::PURPOSE_SUPPORT_VERIFICATION
            )
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);

        $this->assertNotNull(
            $rows[0]->invalidated_at
        );

        $this->assertNull(
            $rows[1]->invalidated_at
        );

        $this->assertNull(
            $rows[1]->consumed_at
        );
    }

    public function test_valid_support_code_can_be_verified(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $code = (string) $issueResponse->json(
            'data.verification_code'
        );

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            [
                'code' => $code,
            ]
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.verified', true);

        $row = \App\Models\OtpCode::query()
            ->where('user_id', $user->id)
            ->where(
                'purpose',
                \App\Models\OtpCode::PURPOSE_SUPPORT_VERIFICATION
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($row->consumed_at);
    }

    public function test_consumed_support_code_cannot_be_reused(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $code = (string) $issueResponse->json(
            'data.verification_code'
        );

        // First use succeeds.
        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $code]
        )
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        // The exact same code cannot be used again.
        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $code]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'SUPPORT_CODE_INVALID'
            );
    }

    public function test_wrong_support_code_is_rejected_and_counts_attempt(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $realCode = (string) $issueResponse->json(
            'data.verification_code'
        );

        // Ensure our deliberately wrong code cannot accidentally equal the real one.
        $wrongCode = $realCode === '000000'
        ? '111111'
        : '000000';

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $wrongCode]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'SUPPORT_CODE_INVALID'
            );

        $row = \App\Models\OtpCode::query()
            ->where('user_id', $user->id)
            ->where(
                'purpose',
                \App\Models\OtpCode::PURPOSE_SUPPORT_VERIFICATION
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->consumed_at);

        // The real code must still work after one incorrect attempt.
        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $realCode]
        )
            ->assertOk()
            ->assertJsonPath('data.verified', true);
    }

    public function test_support_code_is_locked_after_maximum_failed_attempts(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $realCode = (string) $issueResponse->json(
            'data.verification_code'
        );

        $wrongCode = $realCode === '000000'
        ? '111111'
        : '000000';

        // Exhaust all 5 allowed attempts.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(
                '/api/v1/support/verification-code/verify',
                ['code' => $wrongCode]
            )
                ->assertStatus(422)
                ->assertJsonPath(
                    'code',
                    'SUPPORT_CODE_INVALID'
                );
        }

        $row = \App\Models\OtpCode::query()
            ->where('user_id', $user->id)
            ->where(
                'purpose',
                \App\Models\OtpCode::PURPOSE_SUPPORT_VERIFICATION
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(5, $row->attempts);
        $this->assertNull($row->consumed_at);

        // Even the genuine code must now be rejected.
        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $realCode]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'SUPPORT_CODE_INVALID'
            );
    }

    public function test_expired_support_code_cannot_be_verified(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $code = (string) $issueResponse->json(
            'data.verification_code'
        );

        // Support codes expire after 10 minutes.
        $this->travel(10)->minutes();
        $this->travel(1)->second();

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $code]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'SUPPORT_CODE_INVALID'
            );

        $row = \App\Models\OtpCode::query()
            ->where('user_id', $user->id)
            ->where(
                'purpose',
                \App\Models\OtpCode::PURPOSE_SUPPORT_VERIFICATION
            )
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($row->consumed_at);
    }

    public function test_unauthenticated_user_cannot_access_support_verification(): void
    {
        $this->postJson(
            '/api/v1/support/verification-code'
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED'
            );

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => '123456']
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED'
            );
    }

    public function test_suspended_user_cannot_use_support_verification(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Suspended,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/support/verification-code'
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'SUPPORT_VERIFICATION_FORBIDDEN'
            );

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => '123456']
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'SUPPORT_VERIFICATION_FORBIDDEN'
            );
    }

    public function test_unverified_user_cannot_use_support_verification(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => null,
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/support/verification-code'
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'SUPPORT_VERIFICATION_FORBIDDEN'
            );

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => '123456']
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'SUPPORT_VERIFICATION_FORBIDDEN'
            );
    }

    public function test_one_users_support_code_cannot_verify_another_user(): void
    {
        $businessA = $this->makeBusiness();
        $businessB = $this->makeBusiness();

        $userA = $this->makeMember(
            $businessA,
            BusinessRole::Owner,
        );

        $userB = $this->makeMember(
            $businessB,
            BusinessRole::Owner,
        );

        foreach ([$userA, $userB] as $user) {
            $user->forceFill([
                'status'            => AccountStatus::Active,
                'email_verified_at' => now(),
            ])->save();
        }

        // User A generates their support code.
        Sanctum::actingAs($userA);

        $issueResponse = $this->postJson(
            '/api/v1/support/verification-code'
        )->assertOk();

        $userACode = (string) $issueResponse->json(
            'data.verification_code'
        );

        // User B tries to use User A's code.
        Sanctum::actingAs($userB);

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $userACode]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'SUPPORT_CODE_INVALID'
            );

        // User A's code must still be valid for User A.
        Sanctum::actingAs($userA);

        $this->postJson(
            '/api/v1/support/verification-code/verify',
            ['code' => $userACode]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.verified',
                true
            );
    }

    public function test_support_verification_requires_exactly_six_digits(): void
    {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $user->forceFill([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        foreach ([
            [],
            ['code' => ''],
            ['code' => '12345'],
            ['code' => '1234567'],
            ['code' => 'abcdef'],
            ['code' => '12345a'],
        ] as $payload) {
            $this->postJson(
                '/api/v1/support/verification-code/verify',
                $payload
            )
                ->assertStatus(422)
                ->assertJsonValidationErrors('code');
        }
    }

    public function test_support_verification_writes_safe_audit_events(): void
{
    $business = $this->makeBusiness();

    $user = $this->makeMember(
        $business,
        BusinessRole::Owner,
    );

    $user->forceFill([
        'status' => AccountStatus::Active,
        'email_verified_at' => now(),
    ])->save();

    Sanctum::actingAs($user);

    $issueResponse = $this->postJson(
        '/api/v1/support/verification-code'
    )->assertOk();

    $code = (string) $issueResponse->json(
        'data.verification_code'
    );

    $this->postJson(
        '/api/v1/support/verification-code/verify',
        ['code' => $code]
    )
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'action' => 'support.verification_code_issued',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $user->id,
        'action' => 'support.verification_succeeded',
    ]);

    $serializedAudit = AuditLog::query()
        ->where('actor_user_id', $user->id)
        ->get()
        ->toJson();

    $this->assertStringNotContainsString(
        $code,
        $serializedAudit
    );
}

}
