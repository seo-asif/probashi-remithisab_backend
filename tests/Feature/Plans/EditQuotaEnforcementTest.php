<?php

namespace Tests\Feature\Plans;

use App\Enums\BusinessRole;
use App\Models\BusinessMonthlyUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class EditQuotaEnforcementTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_free_accounts_can_edit_even_with_legacy_usage_over_twenty(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);
        $customer = $this->customer();
        $tx = $this->transaction($customer['id']);
        $managerRemittance = $this->remittance();

        $colleague = $this->makeMember($business, BusinessRole::Operator);
        $this->login($colleague);
        $sender = $this->senderRemittance();

        BusinessMonthlyUsage::query()->updateOrCreate([
            'business_id' => $business->id,
            'period_start' => app(\App\Support\Plans\PlanQuotaService::class)
                ->periodStart($business, now()),
        ], ['edits_used' => 200]);

        $this->login($owner);
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", [
            'rate' => '53',
        ])->assertOk();

        $this->patchJson("/api/v1/manager-remittances/{$managerRemittance['id']}", [
            'notes' => 'Edited on Free',
        ])->assertOk();

        $this->login($colleague);
        $this->patchJson("/api/v1/sender-remittances/{$sender['id']}", [
            'notes' => 'Edited on Free',
        ])->assertOk();

        $this->assertSame(1, $this->audits('manager_transaction.updated'));
        $this->assertSame(1, $this->audits('manager_remittance.updated'));
        $this->assertSame(1, $this->audits('sender_remittance.updated'));

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $business->id,
            'edits_used' => 200,
        ]);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonMissingPath('data.plan.allowances.edits');
    }

    public function test_invalid_edit_is_rejected_without_audit(): void
    {
        $this->actAs($this->makeBusiness());
        $remittance = $this->remittance();

        $this->patchJson("/api/v1/manager-remittances/{$remittance['id']}", [
            'charge' => '2000',
        ])->assertStatus(422)
            ->assertJsonPath('error_codes.charge.0', 'charge_more_than_amount');

        $this->assertSame(0, $this->audits('manager_remittance.updated'));
    }

    public function test_noop_financial_edits_remain_without_audit(): void
    {
        $this->actAs($this->makeBusiness());
        $customer = $this->customer();
        $tx = $this->transaction($customer['id']);
        $manager = $this->remittance();
        $sender = $this->senderRemittance();

        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '52'])
            ->assertOk();
        $this->patchJson("/api/v1/manager-remittances/{$manager['id']}", ['amount' => '1000'])
            ->assertOk();
        $this->patchJson("/api/v1/sender-remittances/{$sender['id']}", ['amount' => '500'])
            ->assertOk();

        $this->assertSame(0, $this->audits('manager_transaction.updated'));
        $this->assertSame(0, $this->audits('manager_remittance.updated'));
        $this->assertSame(0, $this->audits('sender_remittance.updated'));
    }
}
