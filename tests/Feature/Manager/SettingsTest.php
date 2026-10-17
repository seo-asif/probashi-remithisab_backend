<?php

namespace Tests\Feature\Manager;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_sending_numbers_are_validated_unique_and_business_owned(): void
    {
        $this->actAs($this->makeBusiness());

        $first = $this->postJson('/api/v1/sending-numbers', ['number' => '017 1111 1111', 'label' => 'Shop bKash'])
            ->assertCreated()->assertJsonPath('data.number', '01711111111')->json('data');

        $this->postJson('/api/v1/sending-numbers', ['number' => '01711111111'])
            ->assertStatus(422)->assertJsonPath('error_codes.number.0', 'sending_number_duplicate');
        $this->postJson('/api/v1/sending-numbers', ['number' => '0171'])
            ->assertStatus(422)->assertJsonPath('errors.number.0', 'Number starting with 01 must be 11 digits.');

        $this->patchJson("/api/v1/sending-numbers/{$first['id']}", ['label' => 'Main'])->assertOk()->assertJsonPath('data.label', 'Main');
        $this->deleteJson("/api/v1/sending-numbers/{$first['id']}")->assertOk();
        $this->postJson('/api/v1/sending-numbers', ['number' => '01711111111'])->assertCreated();   // free again after removal

        $this->actAs($this->makeBusiness());
        $this->getJson('/api/v1/sending-numbers')->assertJsonPath('data.items', []);
    }

    public function test_the_owner_changes_name_timezone_and_currency_and_times_follow(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($business);
        $customer = $this->customer();
        $this->transaction($customer['id'], ['occurred_at' => '2026-09-01 05:00']);   // Fiji: 31 Aug 17:00 UTC

        $this->getJson('/api/v1/manager-transactions?from=2026-09-01&to=2026-09-01')->assertJsonPath('data.meta.total', 1);

        $this->patchJson('/api/v1/business', ['name' => 'Asif Remit', 'timezone' => 'Asia/Dhaka', 'default_currency' => 'usd'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Asif Remit')
            ->assertJsonPath('data.timezone', 'Asia/Dhaka')
            ->assertJsonPath('data.default_currency', 'USD');

        // the same moment is 31 August 23:00 in Dhaka, so it now belongs to 31 August
        $this->getJson('/api/v1/manager-transactions?from=2026-09-01&to=2026-09-01')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/manager-transactions?from=2026-08-31&to=2026-08-31')->assertJsonPath('data.meta.total', 1);

        $this->patchJson('/api/v1/business', ['timezone' => 'Mars/Base'])->assertStatus(422);
    }

    public function test_only_the_owner_or_administrator_changes_business_settings(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business, BusinessRole::Operator);

        $this->patchJson('/api/v1/business', ['name' => 'Mine now'])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->actAs($business, BusinessRole::Administrator);
        $this->patchJson('/api/v1/business', ['name' => 'Renamed'])->assertOk();
    }
}
