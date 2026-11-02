<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_logins_are_limited_per_account_across_ips(): void
    {
        // Disable only route-level IP throttle to isolate the account-level limiter.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = \App\Models\User::factory()->create([
            'password' => 'AValidAdminPassword123',
            'status' => \App\Enums\AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        \App\Models\PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => \App\Enums\AccountStatus::Active,
        ]);
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])
                ->post('/admin/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/admin/login', ['email' => $user->email, 'password' => 'AValidAdminPassword123'])
            ->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_failed_login_writes_security_audit_event(): void
    {
        $user = \App\Models\User::factory()->create([
            'password' => 'AValidAdminPassword123',
            'status' => \App\Enums\AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        \App\Models\PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => \App\Enums\AccountStatus::Active,
        ]);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'admin.login_failed',
        ]);
    }
    public function test_login_form_is_available(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Administrator sign in');
    }

    public function test_password_only_never_authenticates_platform_admin(): void
    {
        $user = User::factory()->create(['password' => 'SecureExamplePassword123', 'status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'SecureExamplePassword123'])
            ->assertRedirect('/admin/mfa');
        $this->assertGuest('web');
        $this->getJson('/admin')->assertUnauthorized();
    }

    public function test_non_admin_cannot_start_mfa(): void
    {
        $user = User::factory()->create(['password' => 'SecureExamplePassword123', 'status' => AccountStatus::Active, 'email_verified_at' => now()]);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'SecureExamplePassword123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertNull(session('admin_pending.user_id'));
    }

    public function test_invalid_password_cannot_start_mfa(): void
    {
        $user = User::factory()->create(['password' => 'SecureExamplePassword123', 'status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'WrongPassword123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertNull(session('admin_pending.user_id'));
    }

    public function test_expired_mfa_pending_challenge_returns_to_login(): void
    {
        $this->withSession(['admin_pending.user_id' => 42, 'admin_pending.created_at' => time() - 301])
            ->get('/admin/mfa')->assertRedirect('/admin/login');
    }
}