<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditReservedFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_extra_metadata_cannot_override_reserved_fields(): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id'          => 123,
            'business_id' => 1,
            'uuid'        => 'REAL_RECORD_UUID',
        ]);

        $old = ['rate' => '52.00'];
        $new = ['rate' => '53.00'];

        $extra = [
            'id'  => 'FAKE_RECORD_ID',
            'old' => ['rate' => '999.00'],
            'new' => ['rate' => '888.00'],
        ];

        MoneyAudit::record(
            'updated',
            $record,
            $old,
            $new,
            $extra
        );

        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.updated')
            ->firstOrFail();

        $metadata = $audit->metadata;

        $this->assertIsArray($metadata);

        // Reserved fields must retain their original values.
        $this->assertSame(
            'REAL_RECORD_UUID',
            $metadata['id'] ?? null
        );

        $this->assertSame(
            '52.00',
            $metadata['old']['rate'] ?? null
        );

        $this->assertSame(
            '53.00',
            $metadata['new']['rate'] ?? null
        );

        // Untrusted override values must never be persisted.
        $encoded = json_encode($metadata);

        $this->assertIsString($encoded);

        foreach ([
            'FAKE_RECORD_ID',
            '999.00',
            '888.00',
        ] as $untrustedValue) {
            $this->assertStringNotContainsString(
                $untrustedValue,
                $encoded
            );
        }
    }
}
