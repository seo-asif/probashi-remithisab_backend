<?php

namespace Tests\Feature\Manager;

use App\Models\ManagerRemittance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class RemittanceTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_m14_incentive_and_one_rounding(): void
    {
        $r = $this->remittance(); // 1,000 FJD, rate 51.55, Bank, 2.5%
        $this->assertSame('52838.75', $r['remit_bdt']);
        $this->assertSame('1288.75', $r['incentive_bdt']);
        $this->assertSame('52.84', $r['effective_rate']);

        // (1000 - 10) x 75.50 x 1.025 = 76,613.625 -> 76,613.63
        $this->assertSame('76613.63', $this->remittance(['rate' => '75.50', 'charge' => '10', 'charge_type' => 'included'])['remit_bdt']);
    }

    public function test_legacy_other_is_readable_and_editable_but_new_other_is_rejected(): void
    {
        $bank = $this->remittance();
        // Simulate a historical record saved before the bank-only form.
        ManagerRemittance::withoutGlobalScopes()->where('uuid', $bank['id'])->firstOrFail()
            ->forceFill([
                'method' => 'Other',
                'remit_bdt' => '35000.50',
                'amount' => '0',
                'rate' => '0',
                'charge' => '0',
                'incentive' => false,
                'incentive_pct' => '0',
            ])->save();

        $this->getJson("/api/v1/manager-remittances/{$bank['id']}")
            ->assertOk()
            ->assertJsonPath('data.method', 'Other')
            ->assertJsonPath('data.remit_bdt', '35000.50');

        $this->patchJson("/api/v1/manager-remittances/{$bank['id']}", ['notes' => 'Legacy note'])
            ->assertOk()
            ->assertJsonPath('data.method', 'Other')
            ->assertJsonPath('data.remit_bdt', '35000.50');

        $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Direct Cash', 'remit_bdt' => '35000.50',
            'occurred_at' => '2026-09-16 09:00',
        ])->assertStatus(422)->assertJsonPath('error_codes.method.0', 'bank_only');
    }

    public function test_incentive_is_0_to_10_and_charge_rules(): void
    {
        $base = ['method' => 'Bank', 'currency' => 'FJD', 'amount' => '100', 'rate' => '55', 'occurred_at' => '2026-09-16 09:00'];

        $this->postJson('/api/v1/manager-remittances', [...$base, 'incentive' => true, 'incentive_pct' => '10.01'])
            ->assertStatus(422)->assertJsonPath('error_codes.incentive_pct.0', 'incentive_too_high');
        $this->postJson('/api/v1/manager-remittances', [...$base, 'incentive' => true])
            ->assertStatus(422)->assertJsonPath('error_codes.incentive_pct.0', 'incentive_invalid');
        $this->postJson('/api/v1/manager-remittances', [...$base, 'charge' => '100.01'])
            ->assertStatus(422)->assertJsonPath('error_codes.charge.0', 'charge_more_than_amount');
        $this->postJson('/api/v1/manager-remittances', [...$base, 'charge' => '100', 'charge_type' => 'included'])
            ->assertStatus(422)->assertJsonPath('error_codes.charge.0', 'charge_not_less_than_amount');
        $this->postJson('/api/v1/manager-remittances', [...$base, 'remit_bdt' => '1'])
            ->assertStatus(422)->assertJsonPath('error_codes.remit_bdt.0', 'calc_mismatch');
        $this->postJson('/api/v1/manager-remittances', [...$base, 'amount' => '10000000', 'rate' => '400'])
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'amount_too_large');
    }

    public function test_bank_remit_bdt_rejects_invalid_client_values(): void
    {
        $base = [
            'method' => 'Bank',
            'currency' => 'FJD',
            'amount' => '100',
            'rate' => '55',
            'occurred_at' => '2026-09-16 09:00',
        ];

        foreach (['5500.004', 'abc', '-5500.00'] as $invalid) {
            $this->postJson('/api/v1/manager-remittances', [
                ...$base,
                'remit_bdt' => $invalid,
            ])->assertStatus(422)
                ->assertJsonPath('error_codes.remit_bdt.0', 'remit_bdt_invalid');
        }

        $this->postJson('/api/v1/manager-remittances', [
            ...$base,
            'remit_bdt' => '5500.00',
        ])->assertCreated()
            ->assertJsonPath('data.remit_bdt', '5500.00');
    }

    public function test_bank_remit_bdt_validation_on_update(): void
    {
        $r = $this->remittance([
            'incentive' => false,
        ]);

        $url = "/api/v1/manager-remittances/{$r['id']}";

        foreach (['5500.004', 'abc', '-5500.00'] as $invalid) {
            $this->patchJson($url, [
                'remit_bdt' => $invalid,
            ])->assertStatus(422)
                ->assertJsonPath(
                    'error_codes.remit_bdt.0',
                    'remit_bdt_invalid'
                );
        }

        $this->patchJson($url, [
            'remit_bdt' => $r['remit_bdt'],
        ])->assertOk()
            ->assertJsonPath(
                'data.remit_bdt',
                $r['remit_bdt']
            );
    }

    public function test_edits_are_checked_as_a_whole_and_logged(): void
    {
        $r = $this->remittance(['incentive' => false]);

        $this->patchJson("/api/v1/manager-remittances/{$r['id']}", ['method' => 'Other'])
            ->assertStatus(422)->assertJsonPath('error_codes.method.0', 'bank_only');
        $this->patchJson("/api/v1/manager-remittances/{$r['id']}", ['charge' => '10', 'charge_type' => 'included'])
            ->assertOk()->assertJsonPath('data.remit_bdt', '51034.50');

        $this->assertSame(1, $this->audits('manager_remittance.updated'));
    }

    public function test_retry_delete_restore_and_isolation(): void
    {
        $id = $this->uuid();
        $first = $this->remittance(['id' => $id]);
        $this->postJson('/api/v1/manager-remittances', ['id' => $id, 'method' => 'Bank', 'amount' => '1', 'rate' => '1', 'occurred_at' => '2026-09-16 09:00'])
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ManagerRemittance::withoutGlobalScopes()->count());

        $this->deleteJson("/api/v1/manager-remittances/{$id}")->assertOk();
        $this->postJson("/api/v1/manager-remittances/{$id}/restore")->assertOk()->assertJsonPath('data.remit_bdt', $first['remit_bdt']);

        $this->actAs($this->makeBusiness());
        $this->getJson("/api/v1/manager-remittances/{$id}")->assertStatus(404);
    }
}
