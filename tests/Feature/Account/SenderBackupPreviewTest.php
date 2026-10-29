<?php

namespace Tests\Feature\Account;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupPreviewTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const URL = '/api/v1/account/backup/sender/preview';

    private function backup(array $recipients = [], array $goals = [], array $remittances = []): array
    {
        return [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => compact('recipients', 'goals', 'remittances'),
        ];
    }

    public function test_preview_requires_authentication(): void
    {
        $this->postJson(self::URL, $this->backup())->assertUnauthorized();
    }

    public function test_empty_backup_is_previewed_without_writes(): void
    {
        $this->actAs($this->makeBusiness());
        $this->postJson(self::URL, $this->backup())->assertOk()
            ->assertJsonPath('data.total_records', 0)
            ->assertJsonPath('data.restore_ready', false)
            ->assertJsonPath('data.datasets.recipients.new', 0);
        $this->assertDatabaseCount('sender_recipients', 0);
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_preview_counts_owned_duplicates_and_new_records_without_writes(): void
    {
        $this->actAs($this->makeBusiness());
        $saved = $this->recipient(['name' => 'Original']);
        $new = '11111111-1111-4111-8111-111111111111';
        $backup = $this->backup(
            [['uuid' => $saved['id']], ['uuid' => $new]],
            [],
            [['uuid' => '33333333-3333-4333-8333-333333333333', 'recipient_uuid' => $new]]
        );

        $this->postJson(self::URL, $backup)->assertOk()
            ->assertJsonPath('data.total_records', 3)
            ->assertJsonPath('data.datasets.recipients.existing', 1)
            ->assertJsonPath('data.datasets.recipients.new', 1)
            ->assertJsonPath('data.datasets.remittances.new', 1);
        $this->assertDatabaseCount('sender_recipients', 1);
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_preview_recognizes_existing_uuid_with_uppercase_letters(): void
    {
        $this->actAs($this->makeBusiness());
        $saved = $this->recipient(['name' => 'Original']);
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        \Illuminate\Support\Facades\DB::table('sender_recipients')
            ->where('uuid', $saved['id'])
            ->update(['uuid' => $uuid]);

        $this->postJson(self::URL, $this->backup([['uuid' => strtoupper($uuid)]]))
            ->assertOk()
            ->assertJsonPath('data.datasets.recipients.existing', 1)
            ->assertJsonPath('data.datasets.recipients.new', 0);
    }

    public function test_preview_does_not_count_other_users_private_records(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        $this->actAs($business, BusinessRole::Operator);
        $other = $this->recipient();
        $this->login($owner);

        $this->postJson(self::URL, $this->backup([['uuid' => $other['id']]]))
            ->assertOk()
            ->assertJsonPath('data.datasets.recipients.existing', 0)
            ->assertJsonPath('data.datasets.recipients.new', 1);
    }

    public function test_invalid_backup_is_rejected_without_writes(): void
    {
        $this->actAs($this->makeBusiness());
        $invalid = $this->backup();
        unset($invalid['datasets']['goals']);
        $this->postJson(self::URL, $invalid)->assertStatus(422);
        $this->assertDatabaseCount('sender_recipients', 0);
    }
}
