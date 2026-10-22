<?php

namespace Tests\Feature\Plans;

use App\Models\ManagerTransaction;
use App\Models\SenderRemittance;
use App\Support\Plans\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class TransactionQuotaRefundTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_sender_deletion_within_ten_minutes_refunds_once_and_restore_reapplies_once(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $remittance = $this->senderRemittance();
        $this->assertSenderUsed($business->id, $user->id, 1);

        $this->deleteJson("/api/v1/sender-remittances/{$remittance['id']}")->assertOk();
        $this->assertSenderUsed($business->id, $user->id, 0);

        $record = $this->findSenderRecord($business->id, $user->id, $remittance['id']);
        $this->assertNotNull($record->quota_refunded_at);

        $this->postJson("/api/v1/sender-remittances/{$remittance['id']}/restore")->assertOk();
        $this->assertSenderUsed($business->id, $user->id, 1);
        $this->assertNotNull(
            $this->findSenderRecord($business->id, $user->id, $remittance['id'])->quota_refund_reapplied_at
        );

        // Repeated restore and second delete cannot credit the same creation twice.
        $this->postJson("/api/v1/sender-remittances/{$remittance['id']}/restore")->assertOk();
        $this->deleteJson("/api/v1/sender-remittances/{$remittance['id']}")->assertOk();
        $this->assertSenderUsed($business->id, $user->id, 1);
    }

    public function test_sender_deletion_after_ten_minutes_never_refunds(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $remittance = $this->senderRemittance();

        $this->travel(601)->seconds();
        $this->deleteJson("/api/v1/sender-remittances/{$remittance['id']}")->assertOk();
        $this->assertSenderUsed($business->id, $user->id, 1);

        $this->postJson("/api/v1/sender-remittances/{$remittance['id']}/restore")->assertOk();
        $this->assertSenderUsed($business->id, $user->id, 1);
    }

    public function test_manager_deletion_refunds_once_and_restore_reapplies_once(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();
        $transaction = $this->transaction($customer['id']);
        $this->assertManagerUsed($business->id, 1);

        $this->deleteJson("/api/v1/manager-transactions/{$transaction['id']}")->assertOk();
        $this->assertManagerUsed($business->id, 0);

        $record = $this->findManagerRecord($business->id, $transaction['id']);
        $this->assertNotNull($record->quota_refunded_at);

        $this->postJson("/api/v1/manager-transactions/{$transaction['id']}/restore")->assertOk();
        $this->assertManagerUsed($business->id, 1);
        $this->assertNotNull(
            $this->findManagerRecord($business->id, $transaction['id'])->quota_refund_reapplied_at
        );

        $this->deleteJson("/api/v1/manager-transactions/{$transaction['id']}")->assertOk();
        $this->assertManagerUsed($business->id, 1);
    }

    public function test_manager_deletion_after_ten_minutes_does_not_refund(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();
        $transaction = $this->transaction($customer['id']);

        $this->travel(601)->seconds();
        $this->deleteJson("/api/v1/manager-transactions/{$transaction['id']}")->assertOk();

        $this->assertManagerUsed($business->id, 1);
    }

    public function test_restoring_refunded_sender_when_original_month_is_full_is_blocked(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $first = $this->senderRemittance();
        $this->deleteJson("/api/v1/sender-remittances/{$first['id']}")->assertOk();

        \App\Models\UserMonthlyUsage::query()->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->update(['sender_remittances_used' => 20]);

        $this->postJson("/api/v1/sender-remittances/{$first['id']}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_SENDER_MONTHLY_LIMIT');

        $this->assertSenderUsed($business->id, $user->id, 20);
        $this->assertNotNull(
            $this->findSenderRecord($business->id, $user->id, $first['id'])->deleted_at
        );
    }

    public function test_restoring_refunded_manager_when_original_month_is_full_is_blocked(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();
        $first = $this->transaction($customer['id']);
        $this->deleteJson("/api/v1/manager-transactions/{$first['id']}")->assertOk();

        \App\Models\BusinessMonthlyUsage::query()->where('business_id', $business->id)
            ->update(['manager_transactions_used' => 20]);

        $this->postJson("/api/v1/manager-transactions/{$first['id']}/restore")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_MANAGER_MONTHLY_LIMIT');

        $this->assertManagerUsed($business->id, 20);
        $this->assertNotNull(
            $this->findManagerRecord($business->id, $first['id'])->deleted_at
        );
    }

    public function test_refund_and_restore_affect_original_month_not_current_month(): void
    {
        // Fiji's local midnight turns October into November at 12:00 UTC.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-31 11:58:00', 'UTC'));
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $user = $this->actAs($business);
        $remittance = $this->senderRemittance();

        $this->travel(3)->minutes(); // November locally, but still within 10 minutes.
        $this->deleteJson("/api/v1/sender-remittances/{$remittance['id']}")->assertOk();

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 0,
        ]);

        $this->postJson("/api/v1/sender-remittances/{$remittance['id']}/restore")->assertOk();
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 1,
        ]);

        $this->assertDatabaseMissing('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'period_start' => '2026-11-01',
        ]);
    }

    /**
     * Test-only direct database lookup, explicitly constrained to the expected
     * business and owner. Production HTTP queries retain fail-closed scopes.
     */
    private function findSenderRecord(int $businessId, int $userId, string $uuid): SenderRemittance
    {
        return SenderRemittance::withoutGlobalScopes()
            ->withTrashed()
            ->where('business_id', $businessId)
            ->where('owner_user_id', $userId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function findManagerRecord(int $businessId, string $uuid): ManagerTransaction
    {
        return ManagerTransaction::withoutGlobalScopes()
            ->withTrashed()
            ->where('business_id', $businessId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function assertSenderUsed(int $businessId, int $userId, int $used): void
    {
        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $businessId,
            'user_id' => $userId,
            'sender_remittances_used' => $used,
        ]);
    }

    private function assertManagerUsed(int $businessId, int $used): void
    {
        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $businessId,
            'manager_transactions_used' => $used,
        ]);
    }
}
