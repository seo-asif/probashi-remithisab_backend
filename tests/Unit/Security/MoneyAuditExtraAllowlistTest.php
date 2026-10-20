<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditExtraAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_extra_metadata_is_persisted(): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id'          => 123,
            'business_id' => 1,
            'uuid'        => 'TEST_RECORD_UUID',
        ]);

        $extra = [
            'payment'          => '550e8400-e29b-41d4-a716-446655440010',
            'reversal'         => '550e8400-e29b-41d4-a716-446655440011',
            'amount_bdt'       => '-500.00',
            'unexpected_field' => 'PRIVATE_INFORMATION',
            'details'          => [
                'secret_data' => 'PRIVATE_NESTED_INFORMATION',
            ],
        ];

        MoneyAudit::record(
            'payment_reversed',
            $record,
            [],
            [],
            $extra
        );

        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.payment_reversed')
            ->firstOrFail();
        $metadata = $audit->metadata;

        $this->assertIsArray($metadata);

        $this->assertSame(
            'TEST_RECORD_UUID',
            $metadata['id'] ?? null
        );

        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440010',
            $metadata['payment'] ?? null
        );

        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440011',
            $metadata['reversal'] ?? null
        );

        $this->assertSame(
            '-500.00',
            $metadata['amount_bdt'] ?? null
        );

        $this->assertArrayNotHasKey('auto', $metadata);
        $this->assertArrayNotHasKey('reason', $metadata);
        $this->assertArrayNotHasKey('payments', $metadata);

        $encoded = json_encode($metadata);

        $this->assertIsString($encoded);

        $this->assertStringNotContainsString(
            'PRIVATE_INFORMATION',
            $encoded
        );

        $this->assertStringNotContainsString(
            'PRIVATE_NESTED_INFORMATION',
            $encoded
        );
    }
}
