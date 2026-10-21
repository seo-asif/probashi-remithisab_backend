<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PlanSecurityRateLimitTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_authenticated_plan_routes_are_rate_limited_per_user(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        /*
         * The general authenticated API limiter currently allows
         * 120 requests per minute per authenticated user.
         *
         * Use a read-only endpoint so this test does not create
         * payments or mutate plan state.
         */
        for ($attempt = 1; $attempt <= 120; $attempt++) {
            $this->getJson('/api/v1/payments/options')
                ->assertOk();
        }

        $this->getJson('/api/v1/payments/options')
            ->assertStatus(429)
            ->assertJsonPath(
                'code',
                'TOO_MANY_REQUESTS',
            );
    }
}
