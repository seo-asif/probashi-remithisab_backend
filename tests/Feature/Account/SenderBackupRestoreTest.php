<?php

namespace Tests\Feature\Account;

use App\Enums\BusinessRole;
use App\Models\UserMonthlyUsage;
use App\Support\Time\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupRestoreTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

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

        $recipientId = DB::table('sender_recipients')
            ->where('uuid', $recipientUuid)->value('id');
        $goalId = DB::table('savings_goals')
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

    public function test_reimport_with_uppercase_uuids_is_idempotent(): void
    {
        $this->actAs($this->makeBusiness());
        $recipientUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $remittanceUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $backup = $this->backup(
            [['uuid' => $recipientUuid, 'name' => 'Mother']],
            [],
            [$this->exampleRemittance($remittanceUuid, $recipientUuid)]
        );

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 2);

        $backup['datasets']['recipients'][0]['uuid'] = strtoupper($recipientUuid);
        $backup['datasets']['remittances'][0]['uuid'] = strtoupper($remittanceUuid);
        $backup['datasets']['remittances'][0]['recipient_uuid'] = strtoupper($recipientUuid);

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 0)
            ->assertJsonPath('data.datasets.recipients.skipped_existing', 1)
            ->assertJsonPath('data.datasets.remittances.skipped_existing', 1);
        $this->assertDatabaseCount('sender_recipients', 1);
        $this->assertDatabaseCount('sender_remittances', 1);
        $this->assertDatabaseHas('user_monthly_usage', [
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
                DB::table($table)->value('deleted_at'),
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
        UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'period_start' => BusinessClock::for($business)
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

    public function test_rejects_received_date_without_received_status(): void
    {
        $this->actAs($this->makeBusiness());
        $row = $this->exampleRemittance();
        $row['received'] = false;

        $this->postJson(self::RESTORE, $this->backup([], [], [$row]))
            ->assertStatus(422);
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_rejects_received_status_without_received_date(): void
    {
        $this->actAs($this->makeBusiness());
        $row = $this->exampleRemittance();
        $row['received_at'] = null;

        $this->postJson(self::RESTORE, $this->backup([], [], [$row]))
            ->assertStatus(422);
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

    public function test_other_users_goal_uuid_is_rejected_without_partial_import(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $privateUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $newUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $this->postJson(self::RESTORE, $this->backup([], [[
            'uuid' => $privateUuid,
            'name' => 'Private House',
            'target_bdt' => '50000.00',
        ]]))->assertOk()->assertJsonPath('data.datasets.goals.created', 1);

        $second = $this->actAs($business, BusinessRole::Operator);
        $response = $this->postJson(self::RESTORE, $this->backup([], [
            ['uuid' => $newUuid, 'name' => 'New House', 'target_bdt' => '1000.00'],
            ['uuid' => strtoupper($privateUuid), 'name' => 'Attempted Copy', 'target_bdt' => '50000.00'],
        ]))->assertStatus(422);

        $this->assertStringNotContainsString('Private House', $response->getContent());
        $this->assertDatabaseHas('savings_goals', [
            'uuid' => $privateUuid,
            'business_id' => $business->id,
            'owner_user_id' => $first->id,
            'name' => 'Private House',
        ]);
        $this->assertDatabaseMissing('savings_goals', ['uuid' => $newUuid]);
        $this->assertDatabaseCount('savings_goals', 1);
        $this->assertDatabaseMissing('savings_goals', [
            'uuid' => $privateUuid,
            'owner_user_id' => $second->id,
        ]);
    }

    public function test_other_users_deleted_remittance_uuid_rolls_back_new_records_and_quota(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $privateUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $newUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $newFamilyUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $private = [
            ...$this->exampleRemittance($privateUuid),
            'notes' => 'Private owner record',
            'deleted_at' => '2026-10-21 09:00:00',
        ];
        $this->postJson(self::RESTORE, $this->backup([], [], [$private]))
            ->assertOk()->assertJsonPath('data.datasets.remittances.created', 1);

        $second = $this->actAs($business, BusinessRole::Operator);
        $response = $this->postJson(self::RESTORE, $this->backup(
            [['uuid' => $newFamilyUuid, 'name' => 'Temporary Family']],
            [],
            [
                $this->exampleRemittance($newUuid, $newFamilyUuid),
                $this->exampleRemittance(strtoupper($privateUuid)),
            ]
        ))->assertStatus(422);

        $this->assertStringNotContainsString('Private owner record', $response->getContent());
        $this->assertDatabaseMissing('sender_recipients', ['uuid' => $newFamilyUuid]);
        $this->assertDatabaseMissing('sender_remittances', ['uuid' => $newUuid]);
        $this->assertDatabaseCount('sender_remittances', 1);
        $this->assertDatabaseHas('sender_remittances', [
            'uuid' => $privateUuid,
            'owner_user_id' => $first->id,
            'business_id' => $business->id,
        ]);
        $this->assertNotNull(DB::table('sender_remittances')
            ->where('uuid', $privateUuid)->value('deleted_at'));
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $first->id,
            'sender_remittances_used' => 1,
        ]);
        $this->assertDatabaseMissing('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $second->id,
        ]);
    }

    public function test_other_business_remittance_uuid_is_rejected_without_quota_changes(): void
    {
        $firstBusiness = $this->makeBusiness();
        $first = $this->actAs($firstBusiness);
        $privateUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->postJson(self::RESTORE, $this->backup([], [], [
            $this->exampleRemittance($privateUuid),
        ]))->assertOk()->assertJsonPath('data.created_total', 1);

        $otherBusiness = $this->makeBusiness();
        $second = $this->actAs($otherBusiness);
        $this->postJson(self::RESTORE, $this->backup([], [], [
            $this->exampleRemittance($privateUuid),
        ]))->assertStatus(422);

        $this->assertDatabaseHas('sender_remittances', [
            'uuid' => $privateUuid,
            'business_id' => $firstBusiness->id,
            'owner_user_id' => $first->id,
        ]);
        $this->assertDatabaseCount('sender_remittances', 1);
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $firstBusiness->id,
            'user_id' => $first->id,
            'sender_remittances_used' => 1,
        ]);
        $this->assertDatabaseMissing('user_monthly_usage', [
            'business_id' => $otherBusiness->id,
            'user_id' => $second->id,
        ]);
    }

    public function test_soft_deleted_import_consumes_quota_once_and_replay_does_not_undelete(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        $familyUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $remittanceUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $deletedAt = '2026-10-21 09:00:00';
        $backup = $this->backup(
            [['uuid' => $familyUuid, 'name' => 'Original Family', 'deleted_at' => $deletedAt]],
            [],
            [[
                ...$this->exampleRemittance($remittanceUuid, $familyUuid),
                'deleted_at' => $deletedAt,
            ]]
        );

        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 2);
        $this->assertDatabaseHas('user_monthly_usage', [
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'sender_remittances_used' => 1,
        ]);
        $this->assertNotNull(DB::table('sender_recipients')
            ->where('uuid', $familyUuid)->value('deleted_at'));
        $this->assertNotNull(DB::table('sender_remittances')
            ->where('uuid', $remittanceUuid)->value('deleted_at'));

        $backup['datasets']['recipients'][0]['name'] = 'Changed Name';
        $backup['datasets']['recipients'][0]['deleted_at'] = null;
        $backup['datasets']['remittances'][0]['deleted_at'] = null;
        $this->postJson(self::RESTORE, $backup)->assertOk()
            ->assertJsonPath('data.created_total', 0)
            ->assertJsonPath('data.datasets.recipients.skipped_existing', 1)
            ->assertJsonPath('data.datasets.remittances.skipped_existing', 1);

        $this->assertDatabaseCount('sender_recipients', 1);
        $this->assertDatabaseCount('sender_remittances', 1);
        $this->assertDatabaseHas('sender_recipients', [
            'uuid' => $familyUuid, 'name' => 'Original Family',
        ]);
        $this->assertNotNull(DB::table('sender_recipients')
            ->where('uuid', $familyUuid)->value('deleted_at'));
        $this->assertNotNull(DB::table('sender_remittances')
            ->where('uuid', $remittanceUuid)->value('deleted_at'));
        $this->assertDatabaseHas('user_monthly_usage', [
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'sender_remittances_used' => 1,
        ]);
    }

    public function test_soft_deleted_import_at_last_free_slot_rolls_back_entire_batch(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        $period = BusinessClock::for($business)
            ->toLocal(now())->format('Y-m-01');
        UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'period_start' => $period,
            'sender_remittances_used' => 19,
        ]);

        $deleted = [
            ...$this->exampleRemittance('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            'deleted_at' => '2026-09-10 09:00:00',
            'occurred_at' => '2026-09-09 10:00:00',
        ];
        $active = $this->exampleRemittance('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $this->postJson(self::RESTORE, $this->backup([], [], [$deleted, $active]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_SENDER_MONTHLY_LIMIT');

        // The first (deleted) row must consume a slot temporarily, but the
        // second row's quota failure must roll both the row and usage back.
        $this->assertDatabaseCount('sender_remittances', 0);
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'period_start' => $period,
            'sender_remittances_used' => 19,
        ]);
    }

    public function test_soft_deleted_family_profiles_still_count_toward_lifetime_cap(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        for ($i = 1; $i <= 5; $i++) {
            $member = $this->recipient([
                'name' => 'Family '.chr(64 + $i),
                'phone' => null,
            ]);
            $this->deleteJson('/api/v1/sender-recipients/'.$member['id'])
                ->assertSuccessful();
        }

        $this->assertDatabaseCount('sender_recipients', 5);
        $newUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->postJson(self::RESTORE, $this->backup([
            ['uuid' => $newUuid, 'name' => 'New Family'],
        ]))->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_FAMILY_LIMIT');

        $this->assertDatabaseMissing('sender_recipients', ['uuid' => $newUuid]);
        $this->assertDatabaseCount('sender_recipients', 5);
    }

    public function test_duplicate_phone_in_same_backup_rejects_with_row_error_and_rolls_back(): void
    {
        $this->actAs($this->makeBusiness());
        $first = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $second = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $response = $this->postJson(self::RESTORE, $this->backup([
            ['uuid' => $first, 'name' => 'Mother', 'phone' => '01811111111'],
            ['uuid' => $second, 'name' => 'Sister', 'phone' => '+8801811111111'],
        ]))->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertArrayHasKey('datasets.recipients.1.phone', $response->json('errors'));
        $this->assertDatabaseCount('sender_recipients', 0);
    }

    public function test_duplicate_phone_with_existing_active_family_rejects_without_writes(): void
    {
        $this->actAs($this->makeBusiness());
        $saved = $this->recipient(['name' => 'Mother', 'phone' => '01811111111']);
        $new = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $response = $this->postJson(self::RESTORE, $this->backup([
            ['uuid' => $new, 'name' => 'Sister', 'phone' => '8801811111111'],
        ]))->assertStatus(422);

        $this->assertArrayHasKey('datasets.recipients.0.phone', $response->json('errors'));
        $this->assertDatabaseCount('sender_recipients', 1);
        $this->assertDatabaseHas('sender_recipients', ['uuid' => $saved['id'], 'name' => 'Mother']);
        $this->assertDatabaseMissing('sender_recipients', ['uuid' => $new]);
    }

    public function test_deleted_family_phone_does_not_block_new_active_import(): void
    {
        $this->actAs($this->makeBusiness());
        $saved = $this->recipient(['name' => 'Mother', 'phone' => '01811111111']);
        $this->deleteJson('/api/v1/sender-recipients/'.$saved['id'])->assertSuccessful();

        $new = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->postJson(self::RESTORE, $this->backup([
            ['uuid' => $new, 'name' => 'Sister', 'phone' => '+8801811111111'],
        ]))->assertOk()->assertJsonPath('data.datasets.recipients.created', 1);

        $this->assertDatabaseCount('sender_recipients', 2);
        $this->assertDatabaseHas('sender_recipients', ['uuid' => $new, 'name' => 'Sister']);
    }

    public function test_same_phone_in_different_owner_is_not_reported_as_duplicate(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $this->recipient(['name' => 'Mother', 'phone' => '01811111111']);
        $second = $this->actAs($business, BusinessRole::Operator);
        $new = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $this->postJson(self::RESTORE, $this->backup([
            ['uuid' => $new, 'name' => 'Sister', 'phone' => '8801811111111'],
        ]))->assertOk()->assertJsonPath('data.datasets.recipients.created', 1);

        $this->assertDatabaseCount('sender_recipients', 2);
        $this->assertDatabaseHas('sender_recipients', ['uuid' => $new, 'owner_user_id' => $second->id]);
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
