<?php

namespace Tests\Feature\Reliability;

use App\Models\AuditLog;
use App\Models\BdtPayment;
use App\Models\BusinessMonthlyUsage;
use App\Models\ManagerTransaction;
use App\Models\SenderRemittance;
use App\Models\StudentExpense;
use App\Models\UserMonthlyUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class FinancialCreationIdempotencyTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_sender_retry_after_quota_is_full_returns_original_without_second_charge(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $id = $this->uuid();

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['id' => $id]))
            ->assertCreated();

        UserMonthlyUsage::query()
            ->where('business_id', $business->id)->where('user_id', $user->id)
            ->update(['sender_remittances_used' => 20]);

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'id' => $id, 'amount' => '1',
        ]))->assertOk()->assertJsonPath('data.amount', '500.00');

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'sender_remittances_used' => 20,
        ]);
        $this->assertSame(1, SenderRemittance::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sender_remittance.created')->count());
    }

    public function test_manager_retry_after_quota_full_does_not_repeat_auto_payment(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();
        $id = $this->uuid();
        $payload = $this->txPayload($customer['id'], [
            'id' => $id,
            'status' => 'BDT Sent',
        ]);

        $this->postJson('/api/v1/manager-transactions', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'bdt_sent');

        BusinessMonthlyUsage::query()->where('business_id', $business->id)
            ->update(['manager_transactions_used' => 20]);

        $this->postJson('/api/v1/manager-transactions', [
            ...$payload, 'amount' => '10',
        ])->assertOk()->assertJsonPath('data.amount', '500.00');

        $this->assertSame(1, ManagerTransaction::withoutGlobalScopes()->count());
        $this->assertSame(1, BdtPayment::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $business->id,
            'manager_transactions_used' => 20,
        ]);
    }

    public function test_student_expense_retry_preserves_original_value_and_audit_once(): void
    {
        $this->actAs($this->makeBusiness());
        $id = $this->uuid();

        $this->postJson('/api/v1/student/expenses', [
            'id' => $id, 'category' => 'food', 'amount' => '12.50', 'currency' => 'FJD',
        ])->assertCreated();

        $this->postJson('/api/v1/student/expenses', [
            'id' => $id, 'category' => 'food', 'amount' => '1000', 'currency' => 'FJD',
        ])->assertOk()->assertJsonPath('data.amount', '12.50');

        $this->assertSame(1, StudentExpense::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'student_expense.created')->count());
    }

    public function test_manager_payment_uuid_retry_does_not_post_twice(): void
    {
        $this->actAs($this->makeBusiness());
        $customer = $this->customer();
        $tx = $this->transaction($customer['id']);
        $id = $this->uuid();

        $this->pay($tx['id'], '500', ['id' => $id])->assertCreated();
        $this->pay($tx['id'], '500', ['id' => $id])->assertOk();

        $this->getJson("/api/v1/manager-transactions/{$tx['id']}")
            ->assertOk()->assertJsonPath('data.paid_bdt', '500.00');
        $this->assertSame(1, BdtPayment::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'manager_transaction.payment_added')->count());
    }
}
