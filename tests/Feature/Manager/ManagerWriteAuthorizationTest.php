<?php

namespace Tests\Feature\Manager;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class ManagerWriteAuthorizationTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_read_only_member_cannot_create_or_modify_manager_records(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();

        $this->actAs($business, BusinessRole::ReadOnlyStaff);

        $this->postJson('/api/v1/customers', [
            'name' => 'Blocked Customer',
        ])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->patchJson('/api/v1/customers/' . $customer['id'], [
            'name' => 'Changed Name',
        ])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->postJson('/api/v1/manager-transactions', $this->txPayload($customer['id']))
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Bank',
            'currency' => 'FJD',
            'amount' => '100',
            'rate' => '50',
            'charge' => '0',
            'charge_type' => 'excluded',
            'incentive' => false,
            'incentive_pct' => '0',
            'occurred_at' => '2026-09-16 09:00',
        ])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_read_only_member_can_still_view_manager_records(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $customer = $this->customer();

        $this->actAs($business, BusinessRole::ReadOnlyStaff);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson('/api/v1/customers/' . $customer['id'])->assertOk();
    }

    public function test_operator_can_write_manager_records(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business, BusinessRole::Operator);

        $this->customer(['name' => 'Operator Customer']);
    }
}
