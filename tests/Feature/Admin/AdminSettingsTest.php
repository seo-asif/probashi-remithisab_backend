<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $mfa = true): User
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => 'CurrentPassword123!',
        ]);
        PlatformAdmin::create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user);
        if ($mfa) {
            $this->withSession([
                'admin_mfa.user_id' => $user->id,
                'admin_mfa.last_activity' => time(),
            ]);
        }
        return $user;
    }

    public function test_guest_cannot_open_settings(): void
    {
        $this->getJson('/admin/settings')->assertUnauthorized();
    }

    public function test_regular_user_cannot_open_settings(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
    }

    public function test_admin_without_mfa_cannot_open_settings(): void
    {
        $this->admin(false);
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_admin_can_view_account_and_mfa_status(): void
    {
        $this->admin();
        $this->get('/admin/settings')->assertOk()->assertSee('Account Settings')
            ->assertSee('Authenticator not configured')
            ->assertSee('Change password');
    }

    public function test_wrong_current_password_does_not_change_password(): void
    {
        $user = $this->admin();
        $this->post('/admin/settings/password', [
            'current_password' => 'WrongPassword123!',
            'password' => 'UpdatedPassword123!',
            'password_confirmation' => 'UpdatedPassword123!',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('CurrentPassword123!', $user->fresh()->password));
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $user = $this->admin();
        $this->post('/admin/settings/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('CurrentPassword123!', $user->fresh()->password));
    }

    public function test_valid_password_change_logs_out_and_audits(): void
    {
        $user = $this->admin();
        $this->post('/admin/settings/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'UpdatedPassword123!',
            'password_confirmation' => 'UpdatedPassword123!',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('UpdatedPassword123!', $user->fresh()->password));
        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'admin.password_changed',
        ]);
    }
}
