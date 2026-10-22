<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class Phase57PrivacyBoundaryTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_private_student_sender_name_and_note_are_not_copied_to_audit_metadata(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/student/money-in', [
            'from_name' => 'Sensitive Family Sender',
            'amount_bdt' => '2750.50',
            'method' => 'Bank',
            'note' => 'Sensitive family note',
        ])->assertCreated();

        $audit = AuditLog::query()
            ->where('action', 'student_money_in.created')
            ->firstOrFail();

        $this->assertArrayNotHasKey('from_name', $audit->metadata['new']);
        $this->assertArrayNotHasKey('note', $audit->metadata['new']);
        $this->assertSame('2750.50', $audit->metadata['new']['amount_bdt']);
        $this->assertStringNotContainsString('Sensitive Family Sender', json_encode($audit->metadata));
        $this->assertStringNotContainsString('Sensitive family note', json_encode($audit->metadata));
    }

    public function test_fee_title_is_not_copied_to_audit_metadata(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/student/fees', [
            'name' => 'Private Student Name',
            'amount' => '350.00',
            'currency' => 'FJD',
            'due_on' => now()->format('Y-m-d'),
        ])->assertCreated();

        $audit = AuditLog::query()
            ->where('action', 'student_fee.created')
            ->firstOrFail();

        $this->assertArrayNotHasKey('name', $audit->metadata['new']);
        $this->assertSame('350.00', $audit->metadata['new']['amount']);
        $this->assertStringNotContainsString('Private Student Name', json_encode($audit->metadata));
    }

    public function test_manager_customer_snapshot_name_is_not_copied_to_audit_metadata(): void
    {
        $this->actAs($this->makeBusiness());
        $customer = $this->customer(['name' => 'Sensitive Customer Name']);

        $this->transaction($customer['id']);

        $audit = AuditLog::query()
            ->where('action', 'manager_transaction.created')
            ->firstOrFail();

        $this->assertArrayNotHasKey('customer_name', $audit->metadata['new']);
        $this->assertSame('500.00', $audit->metadata['new']['amount']);
        $this->assertStringNotContainsString('Sensitive Customer Name', json_encode($audit->metadata));
    }

    public function test_work_date_filters_reject_invalid_and_reversed_ranges(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));

        $this->getJson('/api/v1/work/days?from=2026-02-30')
            ->assertStatus(422)
            ->assertJsonPath('error_codes.from.0', 'date_invalid');

        $this->getJson('/api/v1/work/spending?to=2026-13-01')
            ->assertStatus(422)
            ->assertJsonPath('error_codes.to.0', 'date_invalid');

        $this->getJson('/api/v1/work/summary?from=2026-10-15&to=2026-10-14')
            ->assertStatus(422)
            ->assertJsonPath('error_codes.to.0', 'date_order_invalid');
    }
}
