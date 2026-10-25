<?php

namespace Tests\Feature\Account;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupRestoreTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const RESTORE = '/api/v1/account/backup/sender/restore';
    private const EXPORT = '/api/v1/account/backup/sender';

    private function backup(array $recipients = [], array $goals = [], array $remittances = []): array
    {
        return [
            'format' => 'probashi-remithisab-sender',
            'schema_version' => 1,
            'exported_at' => '2026-10-25T10:00:00+12:00',
            'datasets' => compact('recipients', 'goals', 'remittances'),
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->postJson(self::RESTORE, $this->backup())->assertUnauthorized();
    }

    public function test_empty_restore_is_safe(): void
    {
        $this->actAs($this->makeBusiness());
        $this->postJson(self::RESTORE, $this->backup())->assertOk()
            ->assertJsonPath('data.created_total', 0);
    }

    public function test_merges_valid_family_and_goal_without_overwriting_existing(): void
    {
        $this->actAs($this->makeBusiness());
        $id = '11111111-1111-4111-8111-111111111111';
        $goal = '22222222-2222-4222-8222-222222222222';
        $backup = $this->backup(
            [['uuid' => $id, 'name' => 'Mother', 'relationship' => 'Mother', 'phone' => null]],
            [['uuid' => $goal, 'name' => 'House', 'target_bdt' => '50000.00']]
        );

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.datasets.recipients.created', 1)
            ->assertJsonPath('data.datasets.goals.created', 1);

        $backup['datasets']['recipients'][0]['name'] = 'Changed backup name';
        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.datasets.recipients.skipped_existing', 1)
            ->assertJsonPath('data.created_total', 0);

        $this->assertDatabaseHas('sender_recipients', ['uuid' => $id, 'name' => 'Mother']);
        $this->assertDatabaseCount('sender_recipients', 1);
        $this->assertDatabaseCount('savings_goals', 1);
    }

    public function test_cannot_import_another_users_uuid_and_rolls_back(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $this->recipient(['name' => 'First owner']);
        $this->actAs($business, BusinessRole::Operator);
        $other = $this->recipient(['name' => 'Other owner']);
        $this->login($first);

        $backup = $this->backup([
            ['uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'New Family'],
            ['uuid' => $other['id'], 'name' => 'Stolen UUID'],
        ]);
        $this->postJson(self::RESTORE, $backup)->assertStatus(422);
        $this->assertDatabaseCount('sender_recipients', 2);
    }

    public function test_invalid_remittance_rolls_back_earlier_family_insert(): void
    {
        $this->actAs($this->makeBusiness());
        $backup = $this->backup(
            [['uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'New Family']],
            [],
            [[
                'uuid' => '33333333-3333-4333-8333-333333333333',
                'recipient_type' => 'family',
                'recipient_uuid' => '11111111-1111-4111-8111-111111111111',
                'recipient_name' => 'New Family',
                'currency' => 'FJD', 'amount' => '500.00', 'rate' => '50.00',
                'charge' => '10.00', 'charge_type' => 'excluded',
                'incentive_pct' => '0.00', 'method' => 'Bank',
                'principal' => '500.00', 'incentive_bdt' => '0.00',
                'family_bdt' => '999999.00', 'total_cost' => '510.00',
                'received' => false,
                'occurred_at' => '2026-10-20 10:00:00',
                'timezone' => 'Pacific/Fiji',
            ]]
        );
        $this->postJson(self::RESTORE, $backup)->assertStatus(422);
        $this->assertDatabaseCount('sender_recipients', 0);
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_current_account_export_can_be_imported_idempotently(): void
    {
        $this->actAs($this->makeBusiness());
        $this->recipient();
        $this->senderRemittance();
        $backup = $this->get(self::EXPORT)->assertOk()->json();

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 0)
            ->assertJsonPath('data.datasets.recipients.skipped_existing', 1)
            ->assertJsonPath('data.datasets.remittances.skipped_existing', 1);
        $this->assertDatabaseCount('sender_remittances', 1);
    }
}
