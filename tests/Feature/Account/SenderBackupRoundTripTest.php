<?php

namespace Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

/**
 * Exercises the public API round trip against throwaway test records:
 * create -> export -> simulate loss -> preview -> restore -> export -> replay.
 *
 * Raw deletes below are intentional only inside RefreshDatabase tests.
 * Never delete real account data to validate a restore.
 */
final class SenderBackupRoundTripTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const EXPORT = '/api/v1/account/backup/sender';
    private const PREVIEW = '/api/v1/account/backup/sender/preview';
    private const RESTORE = '/api/v1/account/backup/sender/restore';

    private function remittance(
        string $uuid,
        ?string $recipientUuid,
        ?string $goalUuid,
        string $recipientName,
        ?string $deletedAt = null,
    ): array {
        return [
            'uuid' => $uuid,
            'recipient_type' => $recipientUuid === null ? 'self' : 'family',
            'recipient_uuid' => $recipientUuid,
            'recipient_name' => $recipientName,
            'goal_uuid' => $goalUuid,
            'currency' => 'FJD',
            'amount' => '500.00',
            'rate' => '50.0000',
            'charge' => '10.00',
            'charge_type' => 'excluded',
            'incentive_pct' => '0.00',
            'method' => 'Bank',
            'principal' => '500.00',
            'incentive_bdt' => '0.00',
            'family_bdt' => '25000.00',
            'total_cost' => '510.00',
            'received' => true,
            'received_at' => '2026-10-20 12:00:00',
            'occurred_at' => '2026-10-20 10:00:00',
            'timezone' => 'Pacific/Fiji',
            'deleted_at' => $deletedAt,
        ];
    }

    private function sourceBackup(): array
    {
        $mother = '11111111-1111-4111-8111-111111111111';
        $brother = '22222222-2222-4222-8222-222222222222';
        $house = '33333333-3333-4333-8333-333333333333';
        $otherGoal = '44444444-4444-4444-8444-444444444444';
        $deletedAt = '2026-10-21 09:00:00';

        return [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => [
                'recipients' => [
                    ['uuid' => $mother, 'name' => 'Mother', 'relationship' => 'Mother'],
                    ['uuid' => $brother, 'name' => 'Brother', 'relationship' => 'Brother',
                        'deleted_at' => $deletedAt],
                ],
                'goals' => [
                    ['uuid' => $house, 'name' => 'House', 'target_bdt' => '50000.00'],
                    ['uuid' => $otherGoal, 'name' => 'Savings', 'target_bdt' => '75000.00',
                        'deleted_at' => $deletedAt],
                ],
                'remittances' => [
                    $this->remittance('55555555-5555-4555-8555-555555555555', $mother, $house, 'Mother'),
                    $this->remittance('66666666-6666-4666-8666-666666666666', null, null, 'Myself'),
                    $this->remittance('77777777-7777-4777-8777-777777777777', $brother, $otherGoal, 'Brother',
                        $deletedAt),
                ],
            ],
        ];
    }

    /**
     * Database-generated timestamps change on restore. Everything else in
     * the exported backup must be identical after a complete round trip.
     */
    private function comparableRecords(array $records): array
    {
        $rows = array_map(
            static fn (array $row): array => array_diff_key($row, array_flip(['created_at', 'updated_at'])),
            $records,
        );
        usort($rows, static fn (array $a, array $b): int => strcmp($a['uuid'], $b['uuid']));

        return $rows;
    }

    public function test_exported_sender_backup_round_trips_after_simulated_loss(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);

        $this->postJson(self::RESTORE, $this->sourceBackup())->assertOk()
            ->assertJsonPath('data.created_total', 7);

        $exportResponse = $this->get(self::EXPORT)->assertOk();
        $backup = $exportResponse->json();
        $this->assertSame('probashi-remithisab-sender', $backup['format']);
        $this->assertSame(1, $backup['schema_version']);
        $this->assertCount(2, $backup['datasets']['recipients']);
        $this->assertCount(2, $backup['datasets']['goals']);
        $this->assertCount(3, $backup['datasets']['remittances']);
        $this->assertLessThanOrEqual(
            \App\Services\Account\SenderBackupInput::MAX_BYTES,
            strlen((string) $exportResponse->getContent()),
        );
        $this->assertArrayNotHasKey('owner_user_id', $backup['datasets']['remittances'][0]);

        // Simulated complete loss is permitted only inside a disposable test
        // database managed by RefreshDatabase. Do NOT use this on real data.
        foreach (['sender_remittances', 'savings_goals', 'sender_recipients'] as $table) {
            DB::table($table)
                ->where('business_id', $business->id)
                ->where('owner_user_id', $owner->id)
                ->delete();
        }
        DB::table('user_monthly_usage')
            ->where('business_id', $business->id)
            ->where('user_id', $owner->id)
            ->delete();

        $this->assertDatabaseCount('sender_recipients', 0);
        $this->assertDatabaseCount('savings_goals', 0);
        $this->assertDatabaseCount('sender_remittances', 0);

        $this->postJson(self::PREVIEW, $backup)->assertOk()
            ->assertJsonPath('data.total_records', 7)
            ->assertJsonPath('data.restore_ready', false)
            ->assertJsonPath('data.datasets.recipients.new', 2)
            ->assertJsonPath('data.datasets.goals.new', 2)
            ->assertJsonPath('data.datasets.remittances.new', 3);

        $this->assertDatabaseCount('sender_remittances', 0);
        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 7)
            ->assertJsonPath('data.datasets.recipients.created', 2)
            ->assertJsonPath('data.datasets.goals.created', 2)
            ->assertJsonPath('data.datasets.remittances.created', 3);

        $secondExport = $this->get(self::EXPORT)->assertOk()->json();
        foreach (['recipients', 'goals', 'remittances'] as $dataset) {
            $this->assertSame(
                $this->comparableRecords($backup['datasets'][$dataset]),
                $this->comparableRecords($secondExport['datasets'][$dataset]),
                "Exported $dataset must survive a complete backup round trip.",
            );
        }

        $recipientId = DB::table('sender_recipients')
            ->where('uuid', '11111111-1111-4111-8111-111111111111')->value('id');
        $goalId = DB::table('savings_goals')
            ->where('uuid', '33333333-3333-4333-8333-333333333333')->value('id');

        $this->assertDatabaseHas('sender_remittances', [
            'uuid' => '55555555-5555-4555-8555-555555555555',
            'business_id' => $business->id,
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipientId,
            'goal_id' => $goalId,
            'family_bdt' => '25000.00',
            'total_cost' => '510.00',
        ]);
        foreach ([
            ['sender_recipients', '22222222-2222-4222-8222-222222222222'],
            ['savings_goals', '44444444-4444-4444-8444-444444444444'],
            ['sender_remittances', '77777777-7777-4777-8777-777777777777'],
        ] as [$table, $uuid]) {
            $this->assertNotNull(
                DB::table($table)->where('uuid', $uuid)->value('deleted_at'),
                "Expected $table backup record to remain soft deleted.",
            );
        }

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'sender_remittances_used' => 3,
        ]);

        // Repeating the same downloaded JSON must never create duplicate
        // records or consume monthly quota again.
        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 0)
            ->assertJsonPath('data.skipped_total', 7);
        $this->assertDatabaseCount('sender_recipients', 2);
        $this->assertDatabaseCount('savings_goals', 2);
        $this->assertDatabaseCount('sender_remittances', 3);
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'sender_remittances_used' => 3,
        ]);
    }
}
