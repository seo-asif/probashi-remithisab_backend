<?php

namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageAdminToolsTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private const URL = '/api/v1/platform/daily-messages';

    private function loginAdmin(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, BusinessRole::ReadOnlyStaff);
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        PlatformAdmin::create(['user_id' => $user->id, 'status' => 'active']);
        Sanctum::actingAs($user);
    }

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('messages.csv',
            "code,message_bn,message_en,status\n".$rows);
    }

    public function test_preview_is_read_only_and_identifies_updates(): void
    {
        $this->loginAdmin();
        DailyMessage::create(['code' => 'Q1', 'message_bn' => 'Old', 'status' => 'draft']);
        $this->post(self::URL.'/import/preview', [
            'file' => $this->csv("Q1,New,English,published\nQ2,Second,,draft\n"),
        ], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.create', 1)
            ->assertJsonPath('data.update', 1)
            ->assertJsonPath('data.rows.0.action', 'update')
            ->assertJsonPath('data.rows.1.action', 'create');

        $this->assertDatabaseCount('daily_messages', 1);
        $this->assertDatabaseHas('daily_messages', ['code' => 'Q1', 'message_bn' => 'Old']);
    }

    public function test_preview_rejects_invalid_csv_without_writes(): void
    {
        $this->loginAdmin();
        $this->post(self::URL.'/import/preview', [
            'file' => $this->csv("Q1,Valid,,published\nQ2,Invalid,,wrong\n"),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('daily_messages', 0);
    }

    public function test_bulk_status_updates_only_selected_messages_and_sync_version(): void
    {
        $this->loginAdmin();
        $first = DailyMessage::create(['code' => 'Q1', 'message_bn' => 'First', 'status' => 'draft']);
        $second = DailyMessage::create(['code' => 'Q2', 'message_bn' => 'Second', 'status' => 'draft']);
        $before = $this->getJson('/api/v1/daily-messages/sync')->json('data.version');

        $this->postJson(self::URL.'/bulk-status', [
            'ids' => [$first->uuid], 'status' => 'published',
        ])->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertDatabaseHas('daily_messages', ['code' => 'Q1', 'status' => 'published']);
        $this->assertDatabaseHas('daily_messages', ['code' => 'Q2', 'status' => 'draft']);
        $this->getJson('/api/v1/daily-messages/sync?version='.$before)
            ->assertJsonPath('data.unchanged', false)->assertJsonCount(1, 'data.messages');

        $this->postJson(self::URL.'/bulk-status', [
            'ids' => [$first->uuid, $second->uuid], 'status' => 'draft',
        ])->assertOk();
        $this->assertDatabaseHas('daily_messages', ['code' => 'Q1', 'status' => 'draft']);
    }

    public function test_bulk_status_rejects_missing_and_duplicate_ids_atomically(): void
    {
        $this->loginAdmin();
        $first = DailyMessage::create(['code' => 'Q1', 'message_bn' => 'First', 'status' => 'draft']);
        $missing = '11111111-1111-4111-8111-111111111111';

        $this->postJson(self::URL.'/bulk-status', [
            'ids' => [$first->uuid, $missing], 'status' => 'published',
        ])->assertUnprocessable();

        $this->postJson(self::URL.'/bulk-status', [
            'ids' => [$first->uuid, $first->uuid], 'status' => 'published',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('daily_messages', ['code' => 'Q1', 'status' => 'draft']);
    }

    public function test_non_admin_cannot_preview_or_bulk_publish(): void
    {
        $this->post(self::URL.'/import/preview', [
            'file' => $this->csv("Q1,Text,,published\n"),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->postJson(self::URL.'/bulk-status', [
            'ids' => ['11111111-1111-4111-8111-111111111111'], 'status' => 'published',
        ])->assertUnauthorized();
    }
}
