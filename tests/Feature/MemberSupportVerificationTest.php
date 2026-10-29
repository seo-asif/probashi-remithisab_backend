<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class MemberSupportVerificationTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_member_numbers_are_unique_stored_and_immutable(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertGreaterThan(10000, (int) $first->member_no);
        $this->assertNotSame((int) $first->member_no, (int) $second->member_no);
        $original = (int) $first->member_no;

        $first->update(['name' => 'Updated User']);
        $this->assertSame($original, (int) $first->fresh()->member_no);

        $this->expectException(LogicException::class);
        $first->forceFill(['member_no' => $original + 1])->save();
    }

    public function test_me_returns_authenticated_users_member_number(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, BusinessRole::Owner);
        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.member_no', (int) $user->member_no);
    }

    public function test_unauthenticated_user_cannot_verify_platform_support(): void
    {
        $this->postJson('/api/v1/platform/support/verify', [
            'member_no' => 10001,
            'code' => '123456',
        ])->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_business_owner_cannot_verify_platform_support_but_platform_admin_can(): void
    {
        $member = $this->activeUser();
        Sanctum::actingAs($member);
        $issue = $this->postJson('/api/v1/support/verification-code')->assertOk();
        $code = (string) $issue->json('data.verification_code');

        $this->postJson('/api/v1/platform/support/verify', [
            'member_no' => $member->member_no,
            'code' => $code,
        ])->assertStatus(403)->assertJsonPath('code', 'PLATFORM_ADMIN_FORBIDDEN');

        $adminUser = $this->platformAdminUser();
        Sanctum::actingAs($adminUser);
        $this->postJson('/api/v1/platform/support/verify', [
            'member_no' => $member->member_no,
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.verified', true);
    }

    public function test_platform_support_consumes_code_and_audits_without_recording_plaintext(): void
    {
        $member = $this->activeUser();
        Sanctum::actingAs($member);
        $code = (string) $this->postJson('/api/v1/support/verification-code')
            ->assertOk()->json('data.verification_code');

        $adminUser = $this->platformAdminUser();
        Sanctum::actingAs($adminUser);
        $url = '/api/v1/platform/support/verify';
        $payload = ['member_no' => $member->member_no, 'code' => $code];

        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.member_no', (int) $member->member_no);

        $this->postJson($url, $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPORT_CODE_INVALID');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $adminUser->id,
            'action' => 'support.platform_verification_succeeded',
            'target_id' => $member->id,
        ]);

        $this->assertSame(1, OtpCode::query()
            ->where('user_id', $member->id)
            ->where('purpose', OtpCode::PURPOSE_SUPPORT_VERIFICATION)
            ->whereNotNull('consumed_at')
            ->count());

        $this->assertStringNotContainsString(
            $code,
            AuditLog::query()->get()->toJson()
        );
    }

    public function test_unknown_member_and_invalid_code_use_same_error(): void
    {
        $member = $this->activeUser();
        Sanctum::actingAs($member);
        $code = (string) $this->postJson('/api/v1/support/verification-code')
            ->assertOk()->json('data.verification_code');

        $adminUser = $this->platformAdminUser();
        Sanctum::actingAs($adminUser);

        $url = '/api/v1/platform/support/verify';
        $this->postJson($url, ['member_no' => 999999999, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('code', 'SUPPORT_CODE_INVALID');

        $this->postJson($url, [
            'member_no' => $member->member_no,
            'code' => $code === '000000' ? '111111' : '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'SUPPORT_CODE_INVALID');

        $this->postJson($url, ['member_no' => $member->member_no, 'code' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson($url, ['member_no' => $member->member_no, 'code' => $code])
            ->assertOk();
    }

    public function test_expired_support_code_fails_admin_verification(): void
    {
        $member = $this->activeUser();
        Sanctum::actingAs($member);
        $code = (string) $this->postJson('/api/v1/support/verification-code')
            ->assertOk()->json('data.verification_code');

        $this->travel(601)->seconds();

        $adminUser = $this->platformAdminUser();
        Sanctum::actingAs($adminUser);
        $this->postJson('/api/v1/platform/support/verify', [
            'member_no' => $member->member_no,
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'SUPPORT_CODE_INVALID');
    }

    public function test_suspended_target_cannot_be_verified_by_admin(): void
    {
        $member = $this->activeUser();
        Sanctum::actingAs($member);
        $code = (string) $this->postJson('/api/v1/support/verification-code')
            ->assertOk()->json('data.verification_code');

        $member->forceFill(['status' => AccountStatus::Suspended])->save();

        $adminUser = $this->platformAdminUser();
        Sanctum::actingAs($adminUser);
        $this->postJson('/api/v1/platform/support/verify', [
            'member_no' => $member->member_no,
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'SUPPORT_CODE_INVALID');
    }

    private function activeUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function platformAdminUser(): User
    {
        $user = $this->activeUser();
        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        return $user;
    }
}
