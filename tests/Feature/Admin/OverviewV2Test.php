<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OverviewV2Test extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        $admin = PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
            'admin_mfa.session_version' => (int) $admin->session_version,
        ]);
    }

    public function test_only_verified_platform_admin_can_view_overview(): void
    {
        $this->getJson('/admin')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->get('/admin')->assertForbidden();
    }

    public function test_overview_has_filtered_real_metrics_and_safe_navigation(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 12:00:00', 'UTC'));
        $this->signInAdmin();
        User::factory()->create(['name' => 'Joined Today', 'created_at' => now()]);
        User::factory()->create(['name' => 'Joined Earlier', 'created_at' => now()->subDays(15)]);

        $this->get('/admin?period=7')->assertOk()
            ->assertSee('Good to see you.')
            ->assertSee('Joined Today')
            ->assertDontSee('Joined Earlier')
            ->assertSee('Feature usage')
            ->assertSee('Open payments')
            ->assertSee('Registrations over time');

        $this->get('/admin?period=30')->assertOk()
            ->assertSee('Joined Earlier');
        $this->get('/admin?period=14')->assertSessionHasErrors('period');
    }
}
