<?php

namespace Tests\Unit\Security;

use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditActionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reversal_requires_payment_and_reversal_ids(): void
    {
        $this->expectRejected('payment_reversed', [
            'amount_bdt' => '-250.00',
        ]);
    }

    public function test_reversal_requires_negative_amount(): void
    {
        $this->expectRejected('payment_reversed', [
            'payment' => '550e8400-e29b-41d4-a716-446655440001',
            'reversal' => '550e8400-e29b-41d4-a716-446655440002',
            'amount_bdt' => '250.00',
        ]);
    }

    public function test_manual_payment_requires_payment_id(): void
    {
        $this->expectRejected('payment_added', [
            'amount_bdt' => '250.00',
        ]);
    }

    public function test_removed_payments_cannot_be_empty(): void
    {
        $this->expectRejected('payments_removed', [
            'reason' => 'status bdt_sent -> currency_pending',
            'payments' => [],
        ]);
    }

    public function test_unrelated_extra_fields_are_rejected_for_action(): void
    {
        $this->expectRejected('payment_added', [
            'payment' => '550e8400-e29b-41d4-a716-446655440001',
            'amount_bdt' => '250.00',
            'reason' => 'status bdt_sent -> currency_pending',
        ]);
    }

    private function expectRejected(string $action, array $extra): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440003',
        ]);

        try {
            MoneyAudit::record($action, $record, [], [], $extra);

            $this->fail(
                "Expected InvalidArgumentException for action: {$action}"
            );
        } catch (\InvalidArgumentException $e) {
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }
}
