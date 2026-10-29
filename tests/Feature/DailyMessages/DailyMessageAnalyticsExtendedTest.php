<?php

namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageAnalyticsExtendedTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private function member(): \App\Models\User
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, BusinessRole::ReadOnlyStaff);
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        return $user;
    }

    public function test_analytics_aggregates_unique_likes_and_categories_without_double_counting(): void
    {
        $familyA = DailyMessage::create(['code' => 'STAT_F1', 'message_bn' => 'Family A', 'status' => 'published', 'category' => 'family']);
        $familyB = DailyMessage::create(['code' => 'STAT_F2', 'message_bn' => 'Family B', 'status' => 'published', 'category' => 'family']);
        $savings = DailyMessage::create(['code' => 'STAT_S1', 'message_bn' => 'Savings', 'status' => 'published', 'category' => 'savings']);
        $first = $this->member();
        $second = $this->member();

        Sanctum::actingAs($first);
        $this->putJson('/api/v1/daily-messages/'.$familyA->uuid.'/like')->assertOk();
        $this->putJson('/api/v1/daily-messages/'.$familyA->uuid.'/like')->assertOk();
        $this->putJson('/api/v1/daily-messages/'.$familyB->uuid.'/like')->assertOk();

        Sanctum::actingAs($second);
        $this->putJson('/api/v1/daily-messages/'.$familyA->uuid.'/like')->assertOk();
        $this->putJson('/api/v1/daily-messages/'.$savings->uuid.'/like')->assertOk();

        $this->getJson('/api/v1/platform/daily-messages/analytics')->assertForbidden();
        PlatformAdmin::create(['user_id' => $second->id, 'status' => 'active']);

        $this->getJson('/api/v1/platform/daily-messages/analytics')->assertOk()
            ->assertJsonPath('data.total_likes', 4)
            ->assertJsonPath('data.unique_likers', 2)
            ->assertJsonPath('data.liked_messages', 3)
            ->assertJsonPath('data.total_messages', 3);

        $ranking = $this->getJson('/api/v1/platform/daily-messages/top-liked?limit=3')->assertOk()->json('data');
        $this->assertSame('STAT_F1', $ranking[0]['code']);
        $this->assertSame(2, (int) $ranking[0]['likes_count']);
        $this->assertSame(['STAT_F1', 'STAT_F2'], array_column(
            $this->getJson('/api/v1/platform/daily-messages/top-liked?category=family')->assertOk()->json('data'), 'code'
        ));

        $categories = collect($this->getJson('/api/v1/platform/daily-messages/category-stats')->assertOk()->json('data.categories'))
            ->keyBy('category');
        $this->assertSame(3, $categories['family']['likes_count']);
        $this->assertSame(2, $categories['family']['messages_count']);
        $this->assertSame(1, $categories['savings']['likes_count']);
        $this->assertSame(1, $categories['savings']['messages_count']);
        $this->assertSame(0, $categories['health']['likes_count']);

        $this->getJson('/api/v1/platform/daily-messages/top-liked?limit=101')->assertUnprocessable();
        $this->getJson('/api/v1/platform/daily-messages/top-liked?category=unknown')->assertUnprocessable();
    }
}
