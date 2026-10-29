<?php

namespace Tests\Feature\DailyMessages;

use App\Models\DailyMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class DailyMessageContractTest extends TestCase
{
    use RefreshDatabase;

    private const SYNC = '/api/v1/daily-messages/sync';
    private const TODAY = '/api/v1/daily-message';

    private function message(string $code, string $status = 'published'): DailyMessage
    {
        return DailyMessage::create([
            'code' => $code,
            'message_bn' => 'বাংলা বার্তা',
            'message_en' => 'English message',
            'category' => 'family',
            'status' => $status,
        ]);
    }

    public function test_empty_dataset_has_stable_sync_contract_and_today_returns_null(): void
    {
        $first = $this->getJson(self::SYNC)->assertOk()
            ->assertJsonPath('data.sync_interval_days', 7)
            ->assertJsonPath('data.unchanged', false)
            ->assertJsonCount(0, 'data.messages');
        $version = $first->json('data.version');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);

        $this->getJson(self::SYNC.'?version='.$version)->assertOk()
            ->assertJsonPath('data.unchanged', true)
            ->assertJsonPath('data.messages', null);
        $this->getJson(self::TODAY)->assertOk()->assertJsonPath('data.message', null);
    }

    public function test_sync_preserves_public_message_fields_and_excludes_drafts(): void
    {
        $published = $this->message('CONTRACT001');
        $this->message('CONTRACT002', 'draft');

        $this->getJson(self::SYNC)->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.id', $published->uuid)
            ->assertJsonPath('data.messages.0.code', 'CONTRACT001')
            ->assertJsonPath('data.messages.0.category', 'family')
            ->assertJsonPath('data.messages.0.bn', 'বাংলা বার্তা')
            ->assertJsonPath('data.messages.0.en', 'English message')
            ->assertJsonMissingPath('data.messages.0.status');
    }

    public function test_publish_edit_unpublish_and_delete_invalidate_version(): void
    {
        $message = $this->message('CONTRACT003', 'draft');
        $emptyVersion = $this->getJson(self::SYNC)->json('data.version');

        $message->update(['status' => 'published']);
        $published = $this->getJson(self::SYNC.'?version='.$emptyVersion)->assertOk()
            ->assertJsonPath('data.unchanged', false);
        $publishedVersion = $published->json('data.version');

        $message->update(['message_en' => 'Updated English message']);
        $edited = $this->getJson(self::SYNC.'?version='.$publishedVersion)->assertOk()
            ->assertJsonPath('data.unchanged', false)
            ->assertJsonPath('data.messages.0.en', 'Updated English message');
        $editedVersion = $edited->json('data.version');

        $message->update(['status' => 'draft']);
        $unpublished = $this->getJson(self::SYNC.'?version='.$editedVersion)->assertOk()
            ->assertJsonPath('data.unchanged', false)
            ->assertJsonCount(0, 'data.messages');
        $this->assertSame($emptyVersion, $unpublished->json('data.version'));

        $message->delete();
        $this->getJson(self::SYNC.'?version='.$emptyVersion)->assertOk()
            ->assertJsonPath('data.unchanged', true)
            ->assertJsonPath('data.messages', null);
    }

    public function test_today_is_stable_for_a_utc_day_and_uses_published_messages(): void
    {
        $this->message('CONTRACT004');
        $this->message('CONTRACT005');
        $this->message('CONTRACT006', 'draft');
        Date::setTestNow('2026-10-25 10:00:00 UTC');
        try {
            $first = $this->getJson(self::TODAY)->assertOk()->assertJsonPath('data.date', '2026-10-25')->json('data.message');
            Date::setTestNow('2026-10-25 23:00:00 UTC');
            $second = $this->getJson(self::TODAY)->assertOk()->json('data.message');
            $this->assertSame($first, $second);
            $this->assertContains($first['code'], ['CONTRACT004', 'CONTRACT005']);
        } finally {
            Date::setTestNow();
        }
    }
}
