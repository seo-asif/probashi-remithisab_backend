<?php

namespace Tests\Feature\Work;

use App\Enums\BusinessRole;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class WorkToolsTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($this->business, BusinessRole::Operator);
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => '6', 'day_hours' => '8', 'ot_multiplier' => '1.5', 'currency' => 'fjd'])->assertOk();
    }

    public function test_w1_overtime_pay_per_day_and_one_entry_per_day(): void
    {
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '10'])
            ->assertOk()->assertJsonPath('data.day_pay', '66.00')->assertJsonPath('data.overtime_h', '2.00');
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '8'])->assertOk()->assertJsonPath('data.day_pay', '48.00');
        $this->putJson('/api/v1/work/days/2026-10-02', ['kind' => 'sick', 'amount' => '20'])->assertOk()->assertJsonPath('data.day_pay', '20.00');
        $this->putJson('/api/v1/work/days/2026-10-03', ['kind' => 'off'])->assertOk()->assertJsonPath('data.day_pay', '0.00');
        $this->postJson('/api/v1/work/bonuses', ['bonus_date' => '2026-10-02', 'amount' => '10'])->assertCreated();

        $this->getJson('/api/v1/work/days?from=2026-10-01&to=2026-10-31')->assertJsonCount(3, 'data.items');
        $this->getJson('/api/v1/work/summary?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data.days_worked', 1)->assertJsonPath('data.work_pay', '48.00')->assertJsonPath('data.leave_pay', '20.00')
            ->assertJsonPath('data.bonus', '10.00')->assertJsonPath('data.period_pay', '78.00')->assertJsonPath('data.currency', 'FJD');

        $this->putJson('/api/v1/work/days/2026-10-04', ['kind' => 'work', 'hours' => '25'])->assertStatus(422)->assertJsonPath('error_codes.hours.0', 'hours_invalid');
        $this->putJson('/api/v1/work/days/' . now()->addDays(3)->format('Y-m-d'), ['kind' => 'work', 'hours' => '8'])->assertStatus(422)->assertJsonPath('error_codes.work_date.0', 'date_in_future');
    }

    public function test_w2_salary_with_loan_cut_and_suggested_dates(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $d) {
            $this->putJson("/api/v1/work/days/{$d}", ['kind' => 'work', 'hours' => '10'])->assertOk();   // 5 x 66 = 330
        }
        $this->postJson('/api/v1/work/bonuses', ['bonus_date' => '2026-10-05', 'amount' => '10'])->assertCreated();   // pay 340
        $this->postJson('/api/v1/work/loans', ['type' => 'take', 'amount' => '1000', 'entry_date' => '2026-09-20'])->assertCreated()->assertJsonPath('data.loan_left', '1000.00');

        $this->getJson('/api/v1/work/salaries/suggest')
            ->assertJsonPath('data.from_date', '2026-10-01')->assertJsonPath('data.to_date', '2026-10-05')
            ->assertJsonPath('data.period_pay', '340.00')->assertJsonPath('data.max_cut', '340.00');

        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-01', 'to_date' => '2026-10-05', 'loan_cut' => '340.01'])
            ->assertStatus(422)->assertJsonPath('error_codes.loan_cut.0', 'loan_cut_more_than_pay');

        $s = $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-01', 'to_date' => '2026-10-05', 'amount_received' => '0', 'loan_cut' => '340'])
            ->assertCreated()->assertJsonPath('data.in_hand', '0.00')->json('data');
        $this->getJson('/api/v1/work/loans')->assertJsonPath('data.loan_left', '660.00');
        $this->getJson('/api/v1/work/salaries')->assertJsonPath('data.unpaid_days', 0);

        // deleting the salary removes its cut
        $this->deleteJson("/api/v1/work/salaries/{$s['id']}")->assertOk();
        $this->getJson('/api/v1/work/loans')->assertJsonPath('data.loan_left', '1000.00');
    }

    public function test_w3_a_cut_bigger_than_the_loan_is_refused(): void
    {
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'sick', 'amount' => '300'])->assertOk();
        $this->postJson('/api/v1/work/loans', ['type' => 'take', 'amount' => '200', 'entry_date' => '2026-09-20'])->assertCreated();

        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'loan_cut' => '250'])
            ->assertStatus(422)->assertJsonPath('error_codes.loan_cut.0', 'loan_cut_more_than_loan')->assertJsonPath('errors.loan_cut.0', 'Only 200.00 loan is left.');
    }

    public function test_w4_salaries_never_overlap_and_need_a_paid_day(): void
    {
        foreach (['2026-10-01', '2026-10-09', '2026-10-12'] as $d) {
            $this->putJson("/api/v1/work/days/{$d}", ['kind' => 'work', 'hours' => '8'])->assertOk();
        }
        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-01', 'to_date' => '2026-10-09'])->assertCreated();

        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-05', 'to_date' => '2026-10-12'])
            ->assertStatus(422)->assertJsonPath('error_codes.from_date.0', 'salary_overlap')
            ->assertJsonPath('errors.from_date.0', 'Salary for 2026-10-01 – 2026-10-09 is already saved. Start from 2026-10-10 or later.');
        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-10', 'to_date' => '2026-10-11'])
            ->assertStatus(422)->assertJsonPath('error_codes.from_date.0', 'salary_no_days');
        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-10', 'to_date' => '2026-10-12'])->assertCreated();
    }

    public function test_loans_never_go_below_zero_and_cuts_belong_to_salaries(): void
    {
        $take = $this->postJson('/api/v1/work/loans', ['type' => 'take', 'amount' => '100', 'entry_date' => '2026-10-01'])->json('data');
        $this->postJson('/api/v1/work/loans', ['type' => 'repay', 'amount' => '100.01', 'entry_date' => '2026-10-02'])
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'repay_more_than_loan');
        $this->postJson('/api/v1/work/loans', ['type' => 'repay', 'amount' => '40', 'entry_date' => '2026-10-02'])->assertCreated()->assertJsonPath('data.loan_left', '60.00');
        $this->postJson('/api/v1/work/loans', ['type' => 'cut', 'amount' => '1', 'entry_date' => '2026-10-02'])->assertStatus(422)->assertJsonPath('error_codes.type.0', 'loan_type_invalid');
        $this->deleteJson("/api/v1/work/loans/{$take['id']}")->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'loan_already_repaid');
    }

    public function test_without_an_hourly_rate_only_the_loan_limits_the_cut(): void
    {
        $this->putJson('/api/v1/work/settings', ['hourly_rate' => null, 'effective_from' => '2026-09-30'])->assertOk()->assertJsonPath('data.has_rate', false);
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '8'])->assertOk()->assertJsonPath('data.day_pay', '0.00');
        $this->postJson('/api/v1/work/loans', ['type' => 'take', 'amount' => '500', 'entry_date' => '2026-09-20'])->assertCreated();

        $this->postJson('/api/v1/work/salaries', ['from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'amount_received' => '400', 'loan_cut' => '300'])->assertCreated();
        $this->getJson('/api/v1/work/loans')->assertJsonPath('data.loan_left', '200.00');
    }

    public function test_spending_entries_and_monthly_totals_per_category(): void
    {
        $this->postJson('/api/v1/work/spending', ['spent_on' => '2026-10-01', 'category' => 'food', 'amount' => '12.50'])->assertCreated()->assertJsonPath('data.currency', 'FJD');
        $this->postJson('/api/v1/work/spending', ['spent_on' => '2026-10-02', 'category' => 'food', 'amount' => '7.50'])->assertCreated();
        $this->postJson('/api/v1/work/spending', ['spent_on' => '2026-09-30', 'category' => 'rent', 'amount' => '100'])->assertCreated();
        $this->postJson('/api/v1/work/spending', ['spent_on' => '2026-10-02', 'category' => 'casino', 'amount' => '-1'])
            ->assertStatus(422)->assertJsonPath('error_codes.category.0', 'category_invalid')->assertJsonPath('error_codes.amount.0', 'amount_invalid');

        $months = $this->getJson('/api/v1/work/spending/summary?period=all')->json('data.months');
        $this->assertSame('2026-10', $months[0]['month']);
        $this->assertSame(['currency' => 'FJD', 'total' => '20.00', 'by_category' => ['food' => '20.00']], $months[0]['by_currency'][0]);
        $this->assertSame('100.00', $months[1]['by_currency'][0]['by_category']['rent']);
    }

    public function test_worker_records_are_private(): void
    {
        $this->putJson('/api/v1/work/days/2026-10-01', ['kind' => 'work', 'hours' => '8'])->assertOk();
        $loan = $this->postJson('/api/v1/work/loans', ['type' => 'take', 'amount' => '100', 'entry_date' => '2026-10-01'])->json('data');

        $this->actAs($this->business, BusinessRole::Owner);
        $this->getJson('/api/v1/work/days')->assertJsonPath('data.items', []);
        $this->getJson('/api/v1/work/loans')->assertJsonPath('data.loan_left', '0.00');
        $this->getJson('/api/v1/work/settings')->assertJsonPath('data.has_rate', false);
        $this->deleteJson("/api/v1/work/loans/{$loan['id']}")->assertStatus(404);
        $this->deleteJson('/api/v1/work/days/2026-10-01')->assertStatus(404);
    }
}
