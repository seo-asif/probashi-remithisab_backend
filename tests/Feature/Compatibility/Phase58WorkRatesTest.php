<?php

namespace Tests\Feature\Compatibility;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58WorkRatesTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
    }

    public function test_first_rate_covers_earlier_days_and_later_rate_has_effective_date(): void
    {
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '6', 'currency' => 'FJD'])->assertOk();
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '8'])
            ->assertOk()->assertJsonPath('data.day_pay', '48.00')->assertJsonPath('data.currency', 'FJD');
        $this->putJson('/api/v1/work/settings', [
            'hourly_rate' => '10', 'currency' => 'EUR', 'effective_from' => '2026-10-03',
        ])->assertOk();
        $this->putJson('/api/v1/work/days/2026-10-03', ['kind' => 'work', 'hours' => '8'])
            ->assertOk()->assertJsonPath('data.day_pay', '80.00')->assertJsonPath('data.currency', 'EUR');

        $this->getJson('/api/v1/work/days?from=2026-10-01&to=2026-10-03')
            ->assertJsonPath('data.items.0.day_pay', '48.00')
            ->assertJsonPath('data.items.0.currency', 'FJD')
            ->assertJsonPath('data.items.1.day_pay', '80.00')
            ->assertJsonPath('data.items.1.currency', 'EUR');

        $this->getJson('/api/v1/work/rates')
            ->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.effective_from', null)
            ->assertJsonPath('data.items.1.effective_from', '2026-10-03');

        $this->getJson('/api/v1/work/summary?from=2026-10-01&to=2026-10-03')
            ->assertOk()->assertJsonPath('data.period_pay', null)
            ->assertJsonPath('data.currency', null)
            ->assertJsonPath('data.by_currency.0.currency', 'EUR')
            ->assertJsonPath('data.by_currency.0.period_pay', '80.00')
            ->assertJsonPath('data.by_currency.1.currency', 'FJD')
            ->assertJsonPath('data.by_currency.1.period_pay', '48.00');

        $this->postJson('/api/v1/work/salaries', [
            'from_date' => '2026-10-01', 'to_date' => '2026-10-03',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_date.0', 'salary_mixed_currency');
    }

    public function test_salary_amount_and_currency_are_frozen_after_rate_changes(): void
    {
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '6', 'currency' => 'FJD'])->assertOk();
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '8'])->assertOk();
        $salary = $this->postJson('/api/v1/work/salaries', [
            'from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'amount_received' => '45',
        ])->assertCreated()->assertJsonPath('data.currency', 'FJD')
            ->assertJsonPath('data.period_pay', '48.00')->json('data');

        $this->putJson('/api/v1/work/settings', [
            'hourly_rate' => '10', 'currency' => 'EUR', 'effective_from' => '2026-10-01',
        ])->assertOk();

        $this->getJson("/api/v1/work/salaries?limit=10")
            ->assertOk()->assertJsonPath('data.items.0.id', $salary['id'])
            ->assertJsonPath('data.items.0.currency', 'FJD')
            ->assertJsonPath('data.items.0.period_pay', '48.00');

        $this->patchJson("/api/v1/work/salaries/{$salary['id']}", ['note' => 'saved salary'])
            ->assertOk()->assertJsonPath('data.period_pay', '48.00')
            ->assertJsonPath('data.currency', 'FJD');

        $this->patchJson("/api/v1/work/salaries/{$salary['id']}", [
            'from_date' => '2026-10-02',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_date.0', 'salary_dates_locked');
    }

    public function test_pay_rate_history_is_private_to_its_owner(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($business);
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '20', 'currency' => 'EUR'])->assertOk();
        $this->getJson('/api/v1/work/rates')->assertJsonCount(1, 'data.items');
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $this->getJson('/api/v1/work/rates')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_future_effective_date_is_rejected(): void
    {
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '6', 'currency' => 'FJD'])->assertOk();
        $tomorrow = now('Pacific/Fiji')->addDay()->format('Y-m-d');
        $this->putJson('/api/v1/work/settings', [
            'hourly_rate' => '7', 'effective_from' => $tomorrow,
        ])->assertStatus(422)->assertJsonPath('error_codes.effective_from.0', 'date_in_future');
    }

    public function test_loan_balances_do_not_combine_currencies(): void
    {
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '6', 'currency' => 'FJD'])->assertOk();
        $this->postJson('/api/v1/work/loans', [
            'type' => 'take', 'amount' => '100', 'entry_date' => '2026-10-01',
        ])->assertCreated()->assertJsonPath('data.currency', 'FJD');

        $this->putJson('/api/v1/work/settings', [
            'hourly_rate' => '10', 'currency' => 'EUR', 'effective_from' => '2026-10-02',
        ])->assertOk();
        $this->postJson('/api/v1/work/loans', [
            'type' => 'take', 'amount' => '50', 'entry_date' => '2026-10-03',
        ])->assertCreated()->assertJsonPath('data.currency', 'EUR');

        $this->getJson('/api/v1/work/loans')->assertOk()
            ->assertJsonPath('data.loan_left', '50.00')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.by_currency.0.currency', 'EUR')
            ->assertJsonPath('data.by_currency.0.loan_left', '50.00')
            ->assertJsonPath('data.by_currency.1.currency', 'FJD')
            ->assertJsonPath('data.by_currency.1.loan_left', '100.00');

        $this->postJson('/api/v1/work/loans', [
            'type' => 'repay', 'currency' => 'EUR', 'amount' => '51', 'entry_date' => '2026-10-04',
        ])->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'repay_more_than_loan');
    }
}
