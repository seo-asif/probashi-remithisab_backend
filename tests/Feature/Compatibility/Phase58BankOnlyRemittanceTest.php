<?php

namespace Tests\Feature\Compatibility;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58BankOnlyRemittanceTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_new_remittance_defaults_to_bank_when_method_is_omitted(): void
    {
        $response = $this->postJson('/api/v1/manager-remittances', [
            'currency' => 'FJD', 'amount' => '100', 'rate' => '52',
            'charge' => '0', 'charge_type' => 'excluded',
            'incentive' => false, 'occurred_at' => '2026-09-16 09:00',
        ])->assertCreated();

        $response->assertJsonPath('data.method', 'Bank')
            ->assertJsonPath('data.remit_bdt', '5200.00');
    }

    public function test_all_legacy_method_choices_are_rejected_for_new_remittances(): void
    {
        foreach (['Account', 'Bkash', 'Nagad', 'Rocket', 'Other', 'Direct Cash'] as $method) {
            $this->postJson('/api/v1/manager-remittances', [
                'method' => $method, 'currency' => 'FJD',
                'amount' => '100', 'rate' => '52',
                'occurred_at' => '2026-09-16 09:00',
            ])->assertStatus(422)->assertJsonPath('error_codes.method.0', 'bank_only');
        }
    }

    public function test_existing_bank_record_cannot_switch_to_nonbank_method(): void
    {
        $bank = $this->remittance();
        $this->patchJson("/api/v1/manager-remittances/{$bank['id']}", [
            'method' => 'Nagad',
        ])->assertStatus(422)->assertJsonPath('error_codes.method.0', 'bank_only');
        $this->getJson("/api/v1/manager-remittances/{$bank['id']}")
            ->assertJsonPath('data.method', 'Bank');
    }

    public function test_duplicate_uuid_retry_still_returns_original_record(): void
    {
        $id = $this->uuid();
        $bank = $this->remittance(['id' => $id]);
        $this->postJson('/api/v1/manager-remittances', [
            'id' => $id, 'method' => 'Other',
        ])->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.method', 'Bank')
            ->assertJsonPath('data.remit_bdt', $bank['remit_bdt']);
    }
}
