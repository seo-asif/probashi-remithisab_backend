<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OverviewV3Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        $admin = PlatformAdmin::query()->create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
            'admin_mfa.session_version' => (int) $admin->session_version,
        ]);
    }

    public function test_overview_stays_protected(): void
    {
        $this->getJson('/admin')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_overview_shows_real_recent_users_and_navigation(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 12:00:00', 'UTC'));
        $this->admin();
        User::factory()->create(['name' => 'Recent Example', 'created_at' => now()]);
        User::factory()->create(['name' => 'Older Example', 'created_at' => now()->subDays(20)]);
        $this->get('/admin?period=7')->assertOk()
            ->assertSee('YOUR DAILY BRIEF')->assertSee('Recent Example')
            ->assertDontSee('Older Example')->assertSee('What happened recently')
            ->assertSee('Registrations over time')->assertSee('Review payment queue');
        $this->get('/admin?period=30')->assertOk()->assertSee('Older Example');
        $this->get('/admin?period=15')->assertSessionHasErrors('period');
    }
}
