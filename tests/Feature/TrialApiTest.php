<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\BusinessSubscription;
use App\Support\Plans\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class TrialApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_unauthenticated_user_cannot_start_trial(): void
    {
        $this->postJson('/api/v1/plan/trial')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertDatabaseCount(
            'business_subscriptions',
            0,
        );
    }

    public function test_owner_can_start_trial(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan/trial')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath(
                'message',
                'Your 7-day Unlimited trial has started.',
            )
            ->assertJsonStructure([
                'data' => [
                    'plan',
                ],
            ]);

        $subscription = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'trial',
            $subscription->access_source,
        );

        $this->assertNotNull(
            $subscription->trial_used_at,
        );
    }

    public function test_administrator_can_start_trial(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember(
            $business,
            BusinessRole::Administrator,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan/trial')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas(
            'business_subscriptions',
            [
                'business_id' => $business->id,
                'tier' => PlanLimits::UNLIMITED,
                'access_source' => 'trial',
            ],
        );
    }

    public function test_operator_cannot_start_trial(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember(
            $business,
            BusinessRole::Operator,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan/trial')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'code',
                'PLAN_TRIAL_FORBIDDEN',
            );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
            ],
        );
    }

    public function test_second_trial_activation_is_rejected(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan/trial')
            ->assertOk();

        $first = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $firstTrialUsedAt = $first->trial_used_at->getTimestamp();
        $firstEndsAt = $first->access_ends_at->getTimestamp();

        $this->postJson('/api/v1/plan/trial')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'code',
                'PLAN_TRIAL_ALREADY_USED',
            );

        $first->refresh();

        $this->assertSame(
            $firstTrialUsedAt,
            $first->trial_used_at->getTimestamp(),
        );

        $this->assertSame(
            $firstEndsAt,
            $first->access_ends_at->getTimestamp(),
        );

        $this->assertSame(
            1,
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->count(),
        );
    }

    public function test_me_reports_unlimited_after_trial_activation(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/plan/trial')
            ->assertOk();

        $response = $this->getJson('/api/v1/me')
            ->assertOk();

        $plan = $response->json('data.plan');

        $this->assertIsArray($plan);

        /*
         * Phase 4D is the source of truth for the plan snapshot.
         * Verify the effective plan is Unlimited without changing
         * the existing /me response contract here.
         */
        $effectiveTier = $plan['tier']
            ?? $plan['effective_tier']
            ?? null;

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $effectiveTier,
        );

        $this->assertTrue(
            (bool) data_get(
                $plan,
                'entitlements.monthly_pdf',
            )
        );
    }
}