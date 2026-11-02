<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Admin\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AdminMfaFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create([
            'password' => 'TestAdminPassword123',
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        return $user;
    }

    private function passwordStep(User $user): void
    {
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'TestAdminPassword123'])
            ->assertRedirect('/admin/mfa');
    }


    public function test_first_time_mfa_enrollment_is_not_available_from_browser(): void
    {
        $user = \App\Models\User::factory()->create([
            'status' => \App\Enums\AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        \App\Models\PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => \App\Enums\AccountStatus::Active,
        ]);
        $this->withSession([
            'admin_pending.user_id' => $user->id,
            'admin_pending.created_at' => time(),
        ])->get('/admin/mfa')->assertForbidden();
        $this->assertDatabaseCount('platform_admin_mfa_credentials', 0);
    }

    public function test_same_totp_step_cannot_be_reused_for_next_login(): void
    {
        $user = $this->admin();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();
        $secret = Totp::secret();
        AdminMfaCredential::query()->create([
            'platform_admin_id' => $admin->id,
            'secret' => $secret,
            'last_used_step' => null,
        ]);

        $this->passwordStep($user);
        $this->get('/admin/mfa')->assertOk()
            ->assertSee('Verify administrator login');
        $code = Totp::code($secret, intdiv(time(), 30));
        $this->post('/admin/mfa', ['code' => $code])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'web');
        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->passwordStep($user);
        $this->get('/admin/mfa')->assertOk();
        $this->post('/admin/mfa', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_expired_mfa_grant_logs_out_the_browser_session(): void
    {
        $user = $this->admin();
        $this->actingAs($user, 'web');
        $this->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time() - 901,
        ])->get('/admin')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_missing_mfa_grant_revokes_existing_browser_authentication(): void
    {
        $user = $this->admin();
        $this->actingAs($user, 'web');
        $this->get('/admin')->assertForbidden();
        $this->assertGuest('web');
    }
    public function test_recovery_code_requires_pending_password_challenge(): void
    {
        $user = $this->admin();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();
        $secret = Totp::secret();
        AdminMfaCredential::query()->create(['platform_admin_id' => $admin->id, 'secret' => $secret]);
        $code = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\DB::table('platform_admin_mfa_recovery_codes')->insert([
            'platform_admin_id' => $admin->id, 'code_hash' => hash('sha256', $code),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/admin/mfa/recovery', ['recovery_code' => $code])->assertForbidden();
        $this->assertGuest('web');
        $this->assertDatabaseCount('platform_admin_mfa_recovery_codes', 1);
    }

    public function test_recovery_code_is_single_use_and_keeps_totp_enrollment(): void
    {
        $user = $this->admin();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();
        $secret = Totp::secret();
        AdminMfaCredential::query()->create(['platform_admin_id' => $admin->id, 'secret' => $secret]);
        $code = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\DB::table('platform_admin_mfa_recovery_codes')->insert([
            'platform_admin_id' => $admin->id, 'code_hash' => hash('sha256', $code),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->passwordStep($user);
        $this->post('/admin/mfa/recovery', ['recovery_code' => $code])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('platform_admin_mfa_recovery_codes', 0);
        $this->assertDatabaseHas('platform_admin_mfa_credentials', ['platform_admin_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.mfa_recovery_used', 'actor_user_id' => $user->id]);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->passwordStep($user);
        $this->post('/admin/mfa/recovery', ['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
        $this->assertGuest('web');
    }

    public function test_suspended_admin_cannot_recover(): void
    {
        $user = $this->admin();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();
        AdminMfaCredential::query()->create(['platform_admin_id' => $admin->id, 'secret' => Totp::secret()]);
        $code = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\DB::table('platform_admin_mfa_recovery_codes')->insert([
            'platform_admin_id' => $admin->id, 'code_hash' => hash('sha256', $code),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->passwordStep($user);
        $admin->update(['status' => AccountStatus::Suspended]);
        $this->post('/admin/mfa/recovery', ['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
        $this->assertGuest('web');
        $this->assertDatabaseCount('platform_admin_mfa_recovery_codes', 1);
    }
    public function test_expired_challenge_cannot_verify(): void
    {
        $this->withSession(['admin_pending.user_id' => 99, 'admin_pending.created_at' => time() - 301])
            ->post('/admin/mfa', ['code' => '123456'])->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_suspended_admin_cannot_finish_pending_challenge(): void
    {
        $user = $this->admin();
        $this->passwordStep($user);
        PlatformAdmin::query()->where('user_id', $user->id)->update(['status' => AccountStatus::Suspended->value]);
        $this->get('/admin/mfa')->assertForbidden();
    }

    public function test_enrolled_secret_is_encrypted_in_database(): void
    {
        $user = $this->admin();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();
        $secret = Totp::secret();
        AdminMfaCredential::query()->create([
            'platform_admin_id' => $admin->id,
            'secret' => $secret,
            'last_used_step' => null,
        ]);

        $row = AdminMfaCredential::query()->firstOrFail();
        $this->assertSame($secret, $row->secret);
        $this->assertNotSame($secret, \DB::table('platform_admin_mfa_credentials')->value('secret'));

        $this->passwordStep($user);
        $this->get('/admin/mfa')->assertOk();
        $this->post('/admin/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))])
            ->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }
}
