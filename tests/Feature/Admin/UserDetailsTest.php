<?php
namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserDetailsTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        $admin = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);
        return $admin;
    }
    public function test_guest_cannot_view_profile(): void
    {
        $user = User::factory()->create();
        $this->getJson('/admin/users/'.$user->id)->assertUnauthorized();
    }
    public function test_regular_user_cannot_view_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs(User::factory()->create())->get('/admin/users/'.$user->id)->assertForbidden();
    }
    public function test_admin_needs_mfa_for_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin())->get('/admin/users/'.$user->id)->assertForbidden();
    }
    public function test_verified_admin_can_view_profile(): void
    {
        $user = User::factory()->create(['name' => 'Sample Profile User']);
        $admin = $this->admin();
        $this->actingAs($admin)->withSession(['admin_mfa.user_id' => $admin->id, 'admin_mfa.last_activity' => time()])
            ->get('/admin/users/'.$user->id)->assertOk()->assertSee('Sample Profile User')->assertSee($user->email);
    }
    public function test_unknown_user_returns_not_found(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withSession(['admin_mfa.user_id' => $admin->id, 'admin_mfa.last_activity' => time()])
            ->get('/admin/users/99999999')->assertNotFound();
    }
}