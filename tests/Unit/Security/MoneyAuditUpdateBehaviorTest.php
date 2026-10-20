<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditUpdateBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_only_update_creates_sanitized_audit(): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
        ]);

        MoneyAudit::record(
            'updated',
            $record,
            ['pay_to' => 'PRIVATE_OLD_NUMBER'],
            ['pay_to' => 'PRIVATE_NEW_NUMBER']
        );

        // A sensitive-only change must create exactly one audit.
        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.updated')
            ->firstOrFail();

        $metadata = $audit->metadata;

        $this->assertIsArray($metadata);

        // Sensitive values must never be stored in audit metadata.
        $encoded = json_encode($metadata);

        $this->assertIsString($encoded);

        $this->assertStringNotContainsString(
            'PRIVATE_OLD_NUMBER',
            $encoded
        );

        $this->assertStringNotContainsString(
            'PRIVATE_NEW_NUMBER',
            $encoded
        );

        // The sensitive field itself must not be exposed.
        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['old'] ?? []
        );

        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['new'] ?? []
        );
    }

    public function test_unchanged_update_does_not_create_audit(): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
        ]);

        MoneyAudit::record(
            'updated',
            $record,
            [
                'rate' => '52.00',
                'pay_to' => 'PRIVATE_NUMBER',
            ],
            [
                'rate' => '52.00',
                'pay_to' => 'PRIVATE_NUMBER',
            ]
        );

        // Identical snapshots must not create an audit event.
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
