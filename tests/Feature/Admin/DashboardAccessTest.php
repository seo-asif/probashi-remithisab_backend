<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->getJson('/admin')->assertUnauthorized();
    }

    public function test_regular_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_suspended_admin_cannot_access_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Suspended]);
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_active_verified_admin_can_view_foundation(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_mfa_for_another_user_cannot_unlock_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id + 1000,
            'admin_mfa.last_activity' => time(),
        ])->get('/admin')->assertForbidden();
    }

    public function test_expired_mfa_session_cannot_unlock_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time() - 901,
        ])->get('/admin')->assertForbidden();
    }

    public function test_valid_mfa_session_can_view_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
        ])->get('/admin')->assertOk()
            ->assertSee('Platform overview')
            ->assertSee('Foundation only');
    }
}
