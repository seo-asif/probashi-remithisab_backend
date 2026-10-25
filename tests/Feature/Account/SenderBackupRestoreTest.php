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

    private function exampleRemittance(
        string $uuid = '33333333-3333-4333-8333-333333333333',
        ?string $recipientUuid = null,
        ?string $goalUuid = null,
    ): array {
        return [
            'uuid' => $uuid,
            'recipient_type' => $recipientUuid === null ? 'self' : 'family',
            'recipient_uuid' => $recipientUuid,
            'recipient_name' => $recipientUuid === null ? 'Myself' : 'Mother',
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
        ];
    }

    public function test_imports_new_remittance_with_linked_recipient_and_goal_and_is_idempotent(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        $recipientUuid = '11111111-1111-4111-8111-111111111111';
        $goalUuid = '22222222-2222-4222-8222-222222222222';
        $backup = $this->backup(
            [['uuid' => $recipientUuid, 'name' => 'Mother', 'relationship' => 'Mother']],
            [['uuid' => $goalUuid, 'name' => 'House', 'target_bdt' => '50000.00']],
            [$this->exampleRemittance(recipientUuid: $recipientUuid, goalUuid: $goalUuid)]
        );

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 3)
            ->assertJsonPath('data.datasets.remittances.created', 1);

        $recipientId = \Illuminate\Support\Facades\DB::table('sender_recipients')
            ->where('uuid', $recipientUuid)->value('id');
        $goalId = \Illuminate\Support\Facades\DB::table('savings_goals')
            ->where('uuid', $goalUuid)->value('id');
        $this->assertDatabaseHas('sender_remittances', [
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'business_id' => $business->id,
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipientId,
            'goal_id' => $goalId,
            'family_bdt' => '25000.00',
            'total_cost' => '510.00',
            'received' => 1,
        ]);

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 0)
            ->assertJsonPath('data.datasets.remittances.skipped_existing', 1);
        $this->assertDatabaseCount('sender_remittances', 1);
        $this->assertDatabaseCount('user_monthly_usage', 1);
        $this->assertDatabaseHas('user_monthly_usage', [
            'user_id' => $owner->id,
            'sender_remittances_used' => 1,
        ]);
    }

    public function test_import_preserves_soft_deleted_recipient_goal_and_remittance(): void
    {
        $this->actAs($this->makeBusiness());
        $recipientUuid = '11111111-1111-4111-8111-111111111111';
        $goalUuid = '22222222-2222-4222-8222-222222222222';
        $backup = $this->backup(
            [[
                'uuid' => $recipientUuid,
                'name' => 'Mother',
                'deleted_at' => '2026-10-21 09:00:00',
            ]],
            [[
                'uuid' => $goalUuid,
                'name' => 'House',
                'target_bdt' => '50000.00',
                'deleted_at' => '2026-10-21 09:00:00',
            ]],
            [[
                ...$this->exampleRemittance(recipientUuid: $recipientUuid, goalUuid: $goalUuid),
                'deleted_at' => '2026-10-21 09:00:00',
            ]]
        );

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 3);

        foreach (['sender_recipients', 'savings_goals', 'sender_remittances'] as $table) {
            $this->assertNotNull(
                \Illuminate\Support\Facades\DB::table($table)->value('deleted_at'),
                "Expected a soft-deleted $table record."
            );
        }
        $this->get(self::EXPORT)->assertOk()
            ->assertJsonCount(1, 'datasets.recipients')
            ->assertJsonCount(1, 'datasets.goals')
            ->assertJsonCount(1, 'datasets.remittances');
    }

    public function test_full_free_monthly_quota_rejects_restore_and_rolls_back_family(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        \App\Models\UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'period_start' => \App\Support\Time\BusinessClock::for($business)
                ->toLocal(now())->format('Y-m-01'),
            'sender_remittances_used' => 20,
        ]);
        $backup = $this->backup(
            [['uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Mother']],
            [],
            [$this->exampleRemittance(
                recipientUuid: '11111111-1111-4111-8111-111111111111',
            )]
        );

        $this->postJson(self::RESTORE, $backup)->assertStatus(409);
        $this->assertDatabaseCount('sender_recipients', 0);
        $this->assertDatabaseCount('sender_remittances', 0);
        $this->assertDatabaseHas('user_monthly_usage', [
            'user_id' => $owner->id,
            'sender_remittances_used' => 20,
        ]);
    }

    public function test_invalid_financial_totals_are_rejected_before_any_writes(): void
    {
        $this->actAs($this->makeBusiness());
        $bad = $this->exampleRemittance();
        $bad['family_bdt'] = '25000.01';

        $this->postJson(self::RESTORE, $this->backup(
            [['uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Mother']],
            [],
            [$bad],
        ))->assertStatus(422);
        $this->assertDatabaseCount('sender_recipients', 0);
        $this->assertDatabaseCount('sender_remittances', 0);
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
