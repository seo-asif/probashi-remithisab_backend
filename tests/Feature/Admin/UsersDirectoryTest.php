<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_user_directory(): void
    {
        $this->getJson('/admin/users')->assertUnauthorized();
    }

    public function test_regular_user_cannot_open_user_directory(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
    }

    public function test_admin_needs_mfa_to_open_user_directory(): void
    {
        $admin = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);
        $this->actingAs($admin)->get('/admin/users')->assertForbidden();
    }

    public function test_verified_admin_can_search_and_filter_users(): void
    {
        $admin = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);
        User::factory()->create(['name' => 'Unique Searchperson', 'email' => 'searchperson@example.test', 'status' => AccountStatus::Suspended]);
        User::factory()->create(['name' => 'Other Record', 'email' => 'other@example.test', 'status' => AccountStatus::Active]);
        $this->actingAs($admin)->withSession([
            'admin_mfa.user_id' => $admin->id,
            'admin_mfa.last_activity' => time(),
        ])->get('/admin/users?q=Searchperson&status=suspended')
            ->assertOk()->assertSee('Unique Searchperson')->assertDontSee('Other Record');
    }
}