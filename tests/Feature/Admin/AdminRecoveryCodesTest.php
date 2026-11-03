<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Admin\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class AdminRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $grant = true): array
    {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        $admin = PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $secret = Totp::secret();
        AdminMfaCredential::create(['platform_admin_id' => $admin->id, 'secret' => $secret]);
        $this->actingAs($user);
        if ($grant) {
            $this->withSession([
                'admin_mfa.user_id' => $user->id,
                'admin_mfa.last_activity' => time(),
            ]);
        }
        return [$user, $admin, $secret];
    }

    private function payload(string $secret, string $password = 'CurrentPassword123!'): array
    {
        return [
            'current_password' => $password,
            'mfa_code' => Totp::code($secret, intdiv(time(), 30)),
        ];
    }

    public function test_guest_cannot_regenerate(): void
    {
        $this->postJson('/admin/settings/recovery-codes', [])->assertUnauthorized();
    }

    public function test_regular_user_cannot_regenerate(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/settings/recovery-codes', [])->assertForbidden();
    }

    public function test_admin_without_mfa_grant_cannot_regenerate(): void
    {
        [, , $secret] = $this->admin(false);
        $this->post('/admin/settings/recovery-codes', $this->payload($secret))->assertForbidden();
        $this->assertDatabaseCount('platform_admin_mfa_recovery_codes', 0);
    }

    public function test_wrong_password_preserves_old_codes_and_totp_step(): void
    {
        [$user, $admin, $secret] = $this->admin();
        $old = bin2hex(random_bytes(16));
        $this->seedRecovery($admin->id, $old);
        $this->post('/admin/settings/recovery-codes', $this->payload($secret, 'WrongPassword123!'))
            ->assertSessionHasErrors('mfa_code');
        $this->assertDatabaseHas('platform_admin_mfa_recovery_codes', ['code_hash' => hash('sha256', $old)]);
        $this->assertNull(AdminMfaCredential::where('platform_admin_id', $admin->id)->firstOrFail()->last_used_step);
        RateLimiter::clear('admin-recovery-regenerate:'.$user->id);
    }

    public function test_bad_authenticator_code_preserves_old_codes(): void
    {
        [$user, $admin, $secret] = $this->admin();
        $old = bin2hex(random_bytes(16));
        $this->seedRecovery($admin->id, $old);
        $code = Totp::code($secret, intdiv(time(), 30));
        $wrong = str_pad((string) ((int) $code + 1) % 1000000, 6, '0', STR_PAD_LEFT);
        $this->post('/admin/settings/recovery-codes', [
            'current_password' => 'CurrentPassword123!', 'mfa_code' => $wrong,
        ])->assertSessionHasErrors('mfa_code');
        $this->assertDatabaseHas('platform_admin_mfa_recovery_codes', ['code_hash' => hash('sha256', $old)]);
        RateLimiter::clear('admin-recovery-regenerate:'.$user->id);
    }

    public function test_regeneration_replaces_old_codes_and_shows_eight_once(): void
    {
        [$user, $admin, $secret] = $this->admin();
        $old = bin2hex(random_bytes(16));
        $this->seedRecovery($admin->id, $old);
        $response = $this->post('/admin/settings/recovery-codes', $this->payload($secret))
            ->assertOk()->assertSee('Save these codes now');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseMissing('platform_admin_mfa_recovery_codes', ['code_hash' => hash('sha256', $old)]);
        $this->assertSame(8, DB::table('platform_admin_mfa_recovery_codes')->where('platform_admin_id', $admin->id)->count());
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $user->id, 'action' => 'admin.mfa_recovery_regenerated']);
        $this->assertNotNull(AdminMfaCredential::where('platform_admin_id', $admin->id)->firstOrFail()->last_used_step);
        $this->get('/admin/settings')->assertOk()->assertDontSee('Save these codes now');
        $this->post('/admin/settings/recovery-codes', $this->payload($secret))->assertSessionHasErrors('mfa_code');
        $this->assertSame(8, DB::table('platform_admin_mfa_recovery_codes')->where('platform_admin_id', $admin->id)->count());
        RateLimiter::clear('admin-recovery-regenerate:'.$user->id);
    }

    private function seedRecovery(int $adminId, string $code): void
    {
        DB::table('platform_admin_mfa_recovery_codes')->insert([
            'platform_admin_id' => $adminId,
            'code_hash' => hash('sha256', $code),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
