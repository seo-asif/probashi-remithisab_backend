<?php

namespace Tests\Feature\Sender;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderRemittancePreviewTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_preview_uses_server_calculator_without_creating_record(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/sender-remittances/preview', [
            'currency' => 'FJD',
            'amount' => '500.00',
            'rate' => '50.00',
            'charge' => '10.00',
            'charge_type' => 'included',
            'incentive_pct' => '2.50',
        ])->assertOk()
            ->assertJsonPath('data.currency', 'FJD')
            ->assertJsonPath('data.principal', '490.00')
            ->assertJsonPath('data.base_bdt', '24500.00')
            ->assertJsonPath('data.incentive_bdt', '612.50')
            ->assertJsonPath('data.family_bdt', '25112.50')
            ->assertJsonPath('data.total_cost', '500.00');

        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_preview_follows_currency_specific_rate_precision(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/sender-remittances/preview', [
            'currency' => 'JPY',
            'amount' => '1000.00',
            'rate' => '0.5234',
            'charge' => '0',
            'charge_type' => 'excluded',
            'incentive_pct' => '0',
        ])->assertOk()
            ->assertJsonPath('data.family_bdt', '523.40');

        $this->postJson('/api/v1/sender-remittances/preview', [
            'currency' => 'FJD',
            'amount' => '100.00',
            'rate' => '50.1234',
            'charge' => '0',
            'charge_type' => 'excluded',
            'incentive_pct' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('error_codes.rate.0', 'rate_invalid');
    }

    public function test_preview_rejects_invalid_included_charge(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/sender-remittances/preview', [
            'currency' => 'FJD',
            'amount' => '100.00',
            'rate' => '50.00',
            'charge' => '100.00',
            'charge_type' => 'included',
            'incentive_pct' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('error_codes.charge.0', 'charge_not_less_than_amount');
    }
}
