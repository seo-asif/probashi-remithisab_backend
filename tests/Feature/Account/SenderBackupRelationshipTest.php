<?php

namespace Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupRelationshipTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const RESTORE = '/api/v1/account/backup/sender/restore';

    public function test_legacy_relationships_restore_without_modification(): void
    {
        $this->actAs($this->makeBusiness());

        $recipients = [
            [
                'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'name' => 'Legacy Spouse',
                'relationship' => 'Wife / Husband',
            ],
            [
                'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'name' => 'Legacy Child',
                'relationship' => 'Son / Daughter',
            ],
        ];

        $backup = [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => [
                'recipients' => $recipients,
                'goals' => [],
                'remittances' => [],
            ],
        ];

        $this->postJson(self::RESTORE, $backup)
            ->assertOk()
            ->assertJsonPath('data.datasets.recipients.created', 2);

        foreach ($recipients as $recipient) {
            $this->assertDatabaseHas('sender_recipients', [
                'uuid' => $recipient['uuid'],
                'relationship' => $recipient['relationship'],
            ]);
        }

        $this->postJson(self::RESTORE, $backup)
            ->assertOk()
            ->assertJsonPath('data.datasets.recipients.skipped_existing', 2);

        foreach ($recipients as $recipient) {
            $this->assertDatabaseHas('sender_recipients', [
                'uuid' => $recipient['uuid'],
                'relationship' => $recipient['relationship'],
            ]);
        }
    }

    public function test_unknown_relationship_is_rejected_during_restore(): void
    {
        $this->actAs($this->makeBusiness());

        $backup = [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => [
                'recipients' => [[
                    'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'name' => 'Invalid Family',
                    'relationship' => 'Unknown Relationship',
                ]],
                'goals' => [],
                'remittances' => [],
            ],
        ];

        $this->postJson(self::RESTORE, $backup)
            ->assertStatus(422);

        $this->assertDatabaseCount('sender_recipients', 0);
    }
}