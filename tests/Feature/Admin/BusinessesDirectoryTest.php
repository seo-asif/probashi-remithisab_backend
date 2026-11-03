<?php
namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessesDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);
        return $admin;
    }

    private function verified(User $admin): static
    {
        return $this->actingAs($admin)->withSession([
            'admin_mfa.user_id' => $admin->id,
            'admin_mfa.last_activity' => time(),
        ]);
    }

    public function test_guest_cannot_access_business_directory(): void
    {
        $this->getJson('/admin/businesses')->assertUnauthorized();
    }

    public function test_non_admin_is_blocked(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/businesses')->assertForbidden();
    }

    public function test_admin_must_complete_mfa(): void
    {
        $this->actingAs($this->admin())->get('/admin/businesses')->assertForbidden();
    }

    public function test_admin_can_search_and_view_business_profile(): void
    {
        $business = Business::factory()->create(['name' => 'Example Business Alpha']);
        Business::factory()->create(['name' => 'Other Business Beta']);
        $this->verified($this->admin())->get('/admin/businesses?q=Example')
            ->assertOk()->assertSee('Example Business Alpha')->assertDontSee('Other Business Beta');
        $this->get('/admin/businesses/'.$business->id)
            ->assertOk()->assertSee('Example Business Alpha')->assertSee('Manager Pro');
    }

    public function test_missing_business_returns_not_found(): void
    {
        $this->verified($this->admin())->get('/admin/businesses/99999999')->assertNotFound();
    }
}