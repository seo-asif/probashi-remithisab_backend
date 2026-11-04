<?php

namespace Tests\Feature\Activity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DailyActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_record_an_active_day(): void
    {
        $this->postJson('/api/v1/activity/daily')->assertUnauthorized();
        $this->assertDatabaseCount('user_active_days', 0);
    }

    public function test_authenticated_calls_are_idempotent_per_utc_day(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-04 23:59:00', 'UTC'));

        $this->postJson('/api/v1/activity/daily')->assertOk()
            ->assertJsonPath('data.activity_date', '2026-11-04');
        $this->postJson('/api/v1/activity/daily')->assertOk();
        $this->assertDatabaseCount('user_active_days', 1);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 00:01:00', 'UTC'));
        $this->postJson('/api/v1/activity/daily')->assertOk()
            ->assertJsonPath('data.activity_date', '2026-11-05');
        $this->assertDatabaseCount('user_active_days', 2);
        $this->assertSame(2, DB::table('user_active_days')->where('user_id', $user->id)->count());
    }

    public function test_authenticated_activity_is_attributed_to_token_owner_only(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/v1/activity/daily', ['user_id' => $second->id, 'activity_date' => '2000-01-01'])
            ->assertOk();

        $this->assertDatabaseHas('user_active_days', ['user_id' => $first->id, 'activity_date' => now('UTC')->toDateString()]);
        $this->assertDatabaseMissing('user_active_days', ['user_id' => $second->id]);
    }
}
