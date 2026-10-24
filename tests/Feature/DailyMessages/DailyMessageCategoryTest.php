<?php

namespace Tests\Feature\DailyMessages;

use App\Models\DailyMessage;
use App\Support\DailyMessageCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyMessageCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_fifteen_category_codes_are_unique_and_localized(): void
    {
        $this->assertCount(15, DailyMessageCategories::codes());
        $this->assertCount(15, array_unique(DailyMessageCategories::codes()));
        foreach (DailyMessageCategories::LABELS as $labels) {
            $this->assertNotEmpty($labels['en']);
            $this->assertNotEmpty($labels['bn']);
        }
    }

    public function test_sync_includes_category_and_category_change_updates_version(): void
    {
        $message = DailyMessage::create([
            'code' => 'CAT001',
            'message_bn' => 'সঞ্চয় করুন',
            'message_en' => 'Save money',
            'status' => 'published',
            'category' => 'savings',
        ]);

        $first = $this->getJson('/api/v1/daily-messages/sync')->assertOk();
        $first->assertJsonPath('data.messages.0.category', 'savings');
        $version = $first->json('data.version');

        $message->update(['category' => 'money_management']);
        $this->getJson('/api/v1/daily-messages/sync?version='.$version)
            ->assertOk()
            ->assertJsonPath('data.unchanged', false)
            ->assertJsonPath('data.messages.0.category', 'money_management');
    }

    public function test_uncategorized_existing_messages_remain_compatible(): void
    {
        DailyMessage::create([
            'code' => 'CAT002',
            'message_bn' => 'শুভ দিন',
            'status' => 'published',
        ]);

        $this->getJson('/api/v1/daily-messages/sync')
            ->assertOk()
            ->assertJsonPath('data.messages.0.category', null);
    }
}
