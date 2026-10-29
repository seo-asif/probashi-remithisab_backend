<?php

namespace Tests\Feature\DailyMessages;

use App\Models\DailyMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyMessageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_sync_returns_published_messages_only(): void
    {
        DailyMessage::create(['code' => 'Q001', 'message_bn' => 'প্রতিদিন সঞ্চয় করুন', 'status' => 'published']);
        DailyMessage::create(['code' => 'Q002', 'message_bn' => 'Draft', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/daily-messages/sync');
        $response->assertOk()->assertJsonPath('data.sync_interval_days', 7)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.code', 'Q001');

        $version = $response->json('data.version');
        $this->getJson('/api/v1/daily-messages/sync?version='.$version)
            ->assertOk()->assertJsonPath('data.unchanged', true)
            ->assertJsonPath('data.messages', null);
    }

    public function test_daily_message_is_available_without_login(): void
    {
        $this->getJson('/api/v1/daily-message')
            ->assertOk()->assertJsonPath('data.message', null);
        DailyMessage::create(['code' => 'Q001', 'message_bn' => 'প্রতিদিন সঞ্চয় করুন', 'status' => 'published']);
        $this->getJson('/api/v1/daily-message')
            ->assertOk()->assertJsonPath('data.message.code', 'Q001');
    }

    public function test_admin_routes_reject_anonymous_users(): void
    {
        $this->getJson('/api/v1/platform/daily-messages')->assertUnauthorized();
        $this->postJson('/api/v1/platform/daily-messages', [
            'code' => 'Q001', 'message_bn' => 'Test', 'status' => 'published',
        ])->assertUnauthorized();
    }
}
