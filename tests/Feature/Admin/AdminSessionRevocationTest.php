<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): array
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => 'CurrentPassword123!',
        ]);
        $admin = PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);
        return [$user, $admin];
    }

    private function grant(User $user, int $version): void
    {
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
            'admin_mfa.session_version' => $version,
        ]);
    }

    public function test_prior_admin_session_is_denied_after_password_change(): void
    {
        [$user, $admin] = $this->setupAdmin();
        $this->grant($user, 0);
        $this->get('/admin/settings')->assertOk();

        // Simulate a password change made in a different browser.
        $user->forceFill(['password' => 'UpdatedPassword123!'])->save();
        $admin->increment('session_version');

        $this->get('/admin/settings')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_current_session_version_allows_access(): void
    {
        [$user, $admin] = $this->setupAdmin();
        $admin->increment('session_version');
        $this->grant($user, 1);
        $this->get('/admin/settings')->assertOk();
    }

    public function test_password_change_advances_version(): void
    {
        [$user, $admin] = $this->setupAdmin();
        $this->grant($user, 0);
        $this->post('/admin/settings/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'UpdatedPassword123!',
            'password_confirmation' => 'UpdatedPassword123!',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('UpdatedPassword123!', $user->fresh()->password));
        $this->assertSame(1, (int) $admin->fresh()->session_version);
        $this->assertGuest('web');
    }

    public function test_unstamped_legacy_session_is_denied_after_version_bump(): void
    {
        [$user, $admin] = $this->setupAdmin();
        $admin->increment('session_version');
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
        ]);
        $this->get('/admin/settings')->assertForbidden();
    }
}
