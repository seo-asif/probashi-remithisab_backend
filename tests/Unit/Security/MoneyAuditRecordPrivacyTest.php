<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyAuditRecordPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_filters_sensitive_metadata(): void
    {
        $record = new ManagerTransaction();

        $record->setRawAttributes([
            'id' => 123,
            'business_id' => 1,
        ]);

        $old = [
            'rate' => '52.00',
            'pay_to' => 'PRIVATE_OLD_NUMBER',
        ];

        $new = [
            'rate' => '53.00',
            'pay_to' => 'PRIVATE_NEW_NUMBER',
        ];

        $extra = [
            'details' => [
                'phone' => 'PRIVATE_PHONE',
                'note' => 'PRIVATE_NOTE',
            ],
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

        $this->assertSame(
            '52.00',
            $metadata['old']['rate'] ?? null
        );

        $this->assertSame(
            '53.00',
            $metadata['new']['rate'] ?? null
        );

        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['old'] ?? []
        );

        $this->assertArrayNotHasKey(
            'pay_to',
            $metadata['new'] ?? []
        );

        $this->assertArrayNotHasKey(
            'details',
            $metadata
        );

        $encoded = json_encode($metadata);

        $this->assertIsString($encoded);

        foreach ([
            'PRIVATE_OLD_NUMBER',
            'PRIVATE_NEW_NUMBER',
            'PRIVATE_PHONE',
            'PRIVATE_NOTE',
        ] as $privateValue) {
            $this->assertStringNotContainsString(
                $privateValue,
                $encoded
            );
        }
    }
}
