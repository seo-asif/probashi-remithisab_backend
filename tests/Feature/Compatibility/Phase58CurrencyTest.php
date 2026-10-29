<?php

namespace Tests\Feature\Compatibility;

use App\Support\Money\CurrencyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58CurrencyTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_currency_matrix_matches_all_sixteen_prototype_limits(): void
    {
        $limits = [
            'FJD' => ['10000', '400', 2], 'SAR' => ['40000', '400', 2],
            'AED' => ['40000', '400', 2], 'QAR' => ['40000', '400', 2],
            'KWD' => ['4000', '1000', 2], 'OMR' => ['5000', '1000', 2],
            'BHD' => ['5000', '1000', 2], 'MYR' => ['40000', '400', 2],
            'SGD' => ['10000', '400', 2], 'KRW' => ['15000000', '1', 4],
            'JPY' => ['1500000', '5', 4], 'USD' => ['10000', '400', 2],
            'AUD' => ['10000', '400', 2], 'NZD' => ['10000', '400', 2],
            'GBP' => ['10000', '400', 2], 'EUR' => ['10000', '400', 2],
        ];
        $this->assertSame(array_keys($limits), CurrencyRules::CURRENCIES);
        foreach ($limits as $currency => [$amount, $rate, $decimals]) {
            $this->assertTrue(CurrencyRules::supported($currency));
            $this->assertSame($amount, CurrencyRules::amountMax($currency));
            $this->assertSame($rate, CurrencyRules::rateMax($currency));
            $this->assertSame($decimals, CurrencyRules::rateDecimals($currency));
        }
        $this->assertFalse(CurrencyRules::supported('XYZ'));
    }

    public function test_sender_preserves_four_decimal_rates(): void
    {
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'currency' => 'KRW', 'amount' => '10000', 'rate' => '0.0025',
            'charge' => '0', 'incentive_pct' => '0',
        ]))->assertCreated()->assertJsonPath('data.rate', '0.0025')
            ->assertJsonPath('data.family_bdt', '25.00');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'currency' => 'JPY', 'amount' => '1000', 'rate' => '1.2345',
            'charge' => '0', 'incentive_pct' => '0',
        ]))->assertCreated()->assertJsonPath('data.rate', '1.2345')
            ->assertJsonPath('data.family_bdt', '1234.50');
    }

    public function test_precision_and_currency_limits_are_enforced(): void
    {
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['currency' => 'FJD', 'rate' => '50.1234']))
            ->assertStatus(422)->assertJsonPath('error_codes.rate.0', 'rate_invalid');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['currency' => 'KRW', 'rate' => '0.00001']))
            ->assertStatus(422)->assertJsonPath('error_codes.rate.0', 'rate_invalid');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['currency' => 'KRW', 'amount' => '15000000.01']))
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'amount_too_large');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['currency' => 'JPY', 'rate' => '5.0001']))
            ->assertStatus(422)->assertJsonPath('error_codes.rate.0', 'rate_too_large');
    }

    public function test_manager_transaction_and_remittance_keep_krw_precision(): void
    {
        $customer = $this->customer();
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($customer['id'], [
            'currency' => 'KRW', 'amount' => '10000', 'rate' => '0.0025',
            'receive_method' => 'Cash', 'status' => 'currency_received',
        ]))->assertCreated()->assertJsonPath('data.rate', '0.0025')
            ->assertJsonPath('data.base_bdt', '25.00');
        $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Bank', 'currency' => 'KRW', 'amount' => '10000',
            'rate' => '0.0025', 'charge' => '0', 'charge_type' => 'excluded',
            'incentive' => false, 'occurred_at' => '2026-09-16 09:00',
        ])->assertCreated()->assertJsonPath('data.rate', '0.0025')
            ->assertJsonPath('data.remit_bdt', '25.00')
            ->assertJsonPath('data.effective_rate', '0.0025');
    }

    public function test_stored_rates_are_normalized_only_when_precision_is_insignificant(): void
    {
        $this->assertSame('52.00', CurrencyRules::storedRate('52.0000', 'FJD'));
        $this->assertSame('52.00', CurrencyRules::storedRate('52.00', 'FJD'));
        $this->assertSame('0.0025', CurrencyRules::storedRate('0.0025', 'KRW'));
        $this->assertSame('1.2345', CurrencyRules::storedRate('1.2345', 'JPY'));
        $this->assertSame('52.1234', CurrencyRules::storedRate('52.1234', 'FJD'));
    }

    public function test_partial_edits_work_with_database_four_decimal_padding(): void
    {
        $sender = $this->postJson('/api/v1/sender-remittances', $this->senderPayload())
            ->assertCreated()->json('data');
        $this->patchJson("/api/v1/sender-remittances/{$sender['id']}", ['notes' => 'updated'])
            ->assertOk()->assertJsonPath('data.rate', '50.00');

        $customer = $this->customer();
        $tx = $this->postJson('/api/v1/manager-transactions', $this->txPayload($customer['id']))
            ->assertCreated()->json('data');
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '52'])
            ->assertOk();
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['status' => 'currency_received'])
            ->assertOk()->assertJsonPath('data.rate', '52.00');

        $remittance = $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Bank', 'currency' => 'FJD', 'amount' => '1000',
            'rate' => '50', 'charge' => '0', 'incentive' => false,
            'occurred_at' => '2026-09-16 09:00',
        ])->assertCreated()->json('data');
        $this->patchJson("/api/v1/manager-remittances/{$remittance['id']}", ['notes' => 'updated'])
            ->assertOk()->assertJsonPath('data.rate', '50.00');
    }

    public function test_changing_currency_requires_new_rate_when_precision_is_incompatible(): void
    {
        $sender = $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'currency' => 'JPY', 'amount' => '1000', 'rate' => '1.2345',
            'charge' => '0', 'incentive_pct' => '0',
        ]))->assertCreated()->json('data');

        $this->patchJson("/api/v1/sender-remittances/{$sender['id']}", ['currency' => 'FJD'])
            ->assertStatus(422)->assertJsonPath('error_codes.rate.0', 'rate_invalid');

        $this->patchJson("/api/v1/sender-remittances/{$sender['id']}", [
            'currency' => 'FJD', 'rate' => '52.50',
        ])->assertOk()->assertJsonPath('data.rate', '52.50');
    }

    public function test_manager_enforces_currency_caps(): void
    {
        $customer = $this->customer();
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($customer['id'], ['currency' => 'KWD', 'amount' => '4000.01']))
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'amount_too_large');
        $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Bank', 'currency' => 'JPY', 'amount' => '1500000.01',
            'rate' => '1', 'charge' => '0', 'occurred_at' => '2026-09-16 09:00',
        ])->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'amount_too_large');
    }
}
