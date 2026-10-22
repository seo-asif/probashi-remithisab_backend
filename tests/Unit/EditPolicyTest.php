<?php

namespace Tests\Unit;

use App\Support\Usage\EditPolicy;
use PHPUnit\Framework\TestCase;

class EditPolicyTest extends TestCase
{
    public function test_saving_without_changes_is_not_an_edit(): void
    {
        $stored = ['amount' => '100.00', 'rate' => '72.5000', 'notes' => null, 'charge' => '2.00'];
        $scales = ['amount' => 2, 'rate' => 4, 'charge' => 2];

        $this->assertFalse(EditPolicy::hasRealChange($stored, ['amount' => '100', 'rate' => '72.5', 'notes' => '', 'charge' => 2], $scales));
        $this->assertFalse(EditPolicy::hasRealChange($stored, ['amount' => 100.0, 'rate' => 72.50], $scales));
    }

    public function test_a_real_change_is_an_edit_and_many_changes_are_still_one_check(): void
    {
        $stored = ['amount' => '100.00', 'rate' => '72.5000', 'notes' => 'a'];
        $scales = ['amount' => 2, 'rate' => 4];

        $this->assertTrue(EditPolicy::hasRealChange($stored, ['amount' => '100.01'], $scales));
        $this->assertTrue(EditPolicy::hasRealChange($stored, ['rate' => '72.5001'], $scales));
        $this->assertTrue(EditPolicy::hasRealChange($stored, ['notes' => 'b'], $scales));
        $this->assertTrue(EditPolicy::hasRealChange($stored, ['amount' => '200', 'rate' => '80'], $scales));
        $this->assertTrue(EditPolicy::hasRealChange(['notes' => null], ['notes' => 'x']));
    }

    public function test_bookkeeping_fields_never_count(): void
    {
        $stored = ['amount' => '100.00', 'updated_at' => '2026-10-12T10:00:00Z'];

        $this->assertFalse(EditPolicy::hasRealChange($stored, [
            'amount' => '100.00', 'updated_at' => '2026-10-13T10:00:00Z', 'client_uuid' => 'abc', 'base_updated_at' => 'x',
        ], ['amount' => 2]));
    }
}
