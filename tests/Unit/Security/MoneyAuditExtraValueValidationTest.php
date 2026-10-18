<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditExtraValueValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_payment_metadata_is_rejected(): void
    {
        $this->assertRejected('payment_added', [
            'payment' => 'NOT_A_VALID_UUID',
            'amount_bdt' => 'PRIVATE_CUSTOMER_INFORMATION',
        ]);
    }

    public function test_negative_reversal_amount_is_accepted(): void
    {
        $record = $this->record();

        $payment = '550e8400-e29b-41d4-a716-446655440000';
        $reversal = '550e8400-e29b-41d4-a716-446655440001';

        MoneyAudit::record('payment_reversed', $record, [], [], [
            'payment' => $payment,
            'reversal' => $reversal,
            'amount_bdt' => '-250.00',
        ]);

        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.payment_reversed')
            ->firstOrFail();

        $metadata = $audit->metadata;

        $this->assertIsArray($metadata);
        $this->assertSame($payment, $metadata['payment'] ?? null);
        $this->assertSame($reversal, $metadata['reversal'] ?? null);
        $this->assertSame('-250.00', $metadata['amount_bdt'] ?? null);
    }

    public function test_arbitrary_status_reason_is_rejected(): void
    {
        $this->assertRejected('payments_removed', [
            'reason' => 'Customer private information',
            'payments' => [],
        ]);
    }

    public function test_nested_payment_private_data_is_rejected(): void
    {
        $this->assertRejected('payments_removed', [
            'reason' => 'status bdt_sent -> currency_pending',
            'payments' => [
                [
                    'id' => '550e8400-e29b-41d4-a716-446655440002',
                    'amount_bdt' => '250.00',
                    'private_data' => 'PRIVATE_INFORMATION',
                ],
            ],
        ]);
    }

    private function assertRejected(string $action, array $extra): void
    {
        try {
            MoneyAudit::record(
                $action,
                $this->record(),
                [],
                [],
                $extra
            );

            $this->fail(
                "Expected InvalidArgumentException for action: {$action}"
            );
        } catch (\InvalidArgumentException $e) {
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    private function record(): ManagerTransaction
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440003',
        ]);

        return $record;
    }
}
