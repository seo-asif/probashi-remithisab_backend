<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditSnapshotRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_only_change_from_model_snapshots_is_audited(): void
    {
        $tx = new ManagerTransaction();

        $tx->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
            'rate' => '52.00',
            'pay_to' => 'PRIVATE_OLD_NUMBER',
        ]);

        // Same snapshot pattern used by TransactionService.
        $old = MoneyAudit::values($tx);

        // Change only a sensitive field.
        $tx->pay_to = 'PRIVATE_NEW_NUMBER';

        $new = MoneyAudit::values($tx);

        // Confirm the snapshots detect the sensitive change.
        $this->assertNotEquals($old, $new);

        MoneyAudit::record(
            'updated',
            $tx,
            $old,
            $new
        );

        // The sensitive-only update must still create an audit event.
        $this->assertDatabaseCount('audit_logs', 1);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.updated')
            ->firstOrFail();

        $metadata = $audit->metadata;

        $this->assertIsArray($metadata);

        // Sensitive fields must not appear in persisted metadata.
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

        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['old'] ?? []
        );

        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['new'] ?? []
        );
    }
}
