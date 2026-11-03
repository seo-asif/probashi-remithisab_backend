<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $mfa = true): User
    {
        $admin = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        PlatformAdmin::create(['user_id' => $admin->id, 'status' => AccountStatus::Active]);
        $this->actingAs($admin);
        if ($mfa) {
            $this->withSession([
                'admin_mfa.user_id' => $admin->id,
                'admin_mfa.last_activity' => time(),
            ]);
        }
        return $admin;
    }

    private function record(string $action, ?User $actor = null, array $extra = []): AuditLog
    {
        $values = array_merge([
            'action' => $action,
            'actor_user_id' => $actor?->id,
            'target_type' => 'PlanPaymentRequest',
            'target_id' => 21,
        ], $extra);
        $createdAt = $values['created_at'] ?? null;
        unset($values['created_at']);
        $log = AuditLog::create($values);
        if ($createdAt !== null) {
            $log->forceFill(['created_at' => $createdAt])->save();
        }
        return $log;
    }

    public function test_guest_cannot_open_activity_logs(): void
    {
        $this->get('/admin/activity-logs')->assertRedirect(route('admin.login'));
    }

    public function test_regular_user_cannot_open_activity_logs(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/activity-logs')->assertForbidden();
    }

    public function test_admin_without_mfa_cannot_open_activity_logs(): void
    {
        $this->admin(false);
        $this->get('/admin/activity-logs')->assertForbidden();
    }

    public function test_authorized_admin_sees_activity_and_sidebar_link(): void
    {
        $admin = $this->admin();
        $this->record('plan_payment.submitted', $admin);
        $this->get('/admin/activity-logs')->assertOk()
            ->assertSee('Activity Logs')->assertSee('plan_payment.submitted')
            ->assertSee('Payment #21')->assertSee($admin->email);
        $this->get('/admin')->assertOk()->assertSee(route('admin.activity-logs'));
    }

    public function test_action_and_date_filters_are_applied(): void
    {
        $this->admin();
        $this->record('plan_payment.approved', null, ['created_at' => '2026-11-01 12:00:00']);
        $this->record('plan_payment.rejected', null, ['created_at' => '2026-10-01 12:00:00']);
        $this->get('/admin/activity-logs?action=plan_payment.approved&from=2026-11-01&to=2026-11-03')
            ->assertOk()->assertSee('plan_payment.approved')->assertDontSee('plan_payment.rejected</span>', false);
    }

    public function test_search_finds_actor_and_target_id(): void
    {
        $this->admin();
        $actor = User::factory()->create(['name' => 'Unique Audit Actor']);
        $this->record('plan_payment.submitted', $actor, ['target_id' => 98765]);
        $this->get('/admin/activity-logs?q=Unique%20Audit%20Actor')->assertOk()->assertSee('Unique Audit Actor');
        $this->get('/admin/activity-logs?q=98765')->assertOk()->assertSee('Payment #98765');
    }

    public function test_validation_rejects_invalid_dates_and_long_search(): void
    {
        $this->admin();
        $this->get('/admin/activity-logs?from=2026-11-03&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/admin/activity-logs?q='.str_repeat('x', 101))->assertSessionHasErrors('q');
    }

    public function test_metadata_ip_and_user_agent_are_not_rendered(): void
    {
        $this->admin();
        $this->record('auth.login_failed', null, [
            'metadata' => ['email_hash' => str_repeat('a', 64)],
            'ip' => '192.0.2.123',
            'user_agent' => 'SECRET_BROWSER_AGENT',
        ]);
        $this->get('/admin/activity-logs')->assertOk()
            ->assertDontSee(str_repeat('a', 64))
            ->assertDontSee('192.0.2.123')
            ->assertDontSee('SECRET_BROWSER_AGENT');
    }

    public function test_activity_is_paginated_twenty_per_page(): void
    {
        $this->admin();
        for ($i = 0; $i < 22; $i++) {
            $this->record('test.activity', null, ['target_id' => 100 + $i]);
        }
        $this->get('/admin/activity-logs')->assertOk()->assertSee('1-20 of 22');
        $this->get('/admin/activity-logs?page=2')->assertOk()->assertSee('21-22 of 22');
    }
}
