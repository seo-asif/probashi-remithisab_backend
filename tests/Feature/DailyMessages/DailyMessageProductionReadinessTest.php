<?php

namespace Tests\Feature\DailyMessages;

use App\Models\DailyMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DailyMessageProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_rejects_malformed_version_tokens(): void
    {
        $this->getJson('/api/v1/daily-messages/sync?version=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/daily-messages/sync?version='.str_repeat('a', 65))->assertUnprocessable();
    }

    public function test_sync_returns_null_messages_for_matching_version(): void
    {
        DailyMessage::create(['code' => 'PROD001', 'message_bn' => 'Hello', 'status' => 'published']);
        $version = $this->getJson('/api/v1/daily-messages/sync')->assertOk()->json('data.version');
        $this->getJson('/api/v1/daily-messages/sync?version='.$version)
            ->assertOk()
            ->assertJsonPath('data.unchanged', true)
            ->assertJsonPath('data.messages', null)
            ->assertJsonPath('data.sync_interval_days', 7);
    }

    public function test_only_published_messages_are_returned_in_stable_order(): void
    {
        DailyMessage::create(['code' => 'PROD002', 'message_bn' => 'Second', 'status' => 'published', 'sort_order' => 2]);
        DailyMessage::create(['code' => 'PROD001', 'message_bn' => 'First', 'status' => 'published', 'sort_order' => 1]);
        DailyMessage::create(['code' => 'PROD003', 'message_bn' => 'Hidden', 'status' => 'draft', 'sort_order' => 0]);
        $this->getJson('/api/v1/daily-messages/sync')->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.code', 'PROD001')
            ->assertJsonPath('data.messages.1.code', 'PROD002');
    }

    public function test_published_order_index_exists(): void
    {
        $this->assertTrue(Schema::hasIndex('daily_messages', 'daily_messages_published_order_idx'));
    }
}
