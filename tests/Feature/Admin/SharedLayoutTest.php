<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SharedLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_layout_compiles_with_real_admin_route_links(): void
    {
        $admin = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);

        $this->actingAs($admin)->withSession([
            'admin_mfa.user_id' => $admin->id,
            'admin_mfa.last_activity' => time(),
        ]);

        $html = view('admin.layouts.app')->render();
        $this->assertStringContainsString(route('admin.dashboard'), $html);
        $this->assertStringContainsString(route('admin.users'), $html);
        $this->assertStringContainsString(route('admin.logout'), $html);
        $this->assertStringContainsString('Administrator navigation', $html);
        $this->assertStringContainsString('Sign out', $html);
    }
}