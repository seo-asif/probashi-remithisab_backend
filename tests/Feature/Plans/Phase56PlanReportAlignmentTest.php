<?php

namespace Tests\Feature\Plans;

use App\Models\BusinessSubscription;
use App\Support\Plans\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class Phase56PlanReportAlignmentTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_free_plan_reports_monthly_pdf_but_not_custom_dates_or_csv(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.plan.tier', PlanLimits::FREE)
            ->assertJsonPath('data.plan.entitlements.basic_pdf', true)
            ->assertJsonPath('data.plan.entitlements.monthly_pdf', true)
            ->assertJsonPath('data.plan.entitlements.custom_date_range', false)
            ->assertJsonPath('data.plan.entitlements.csv_export', false);

        // Basic reports stay readable without forcing a subscription.
        $this->getJson('/api/v1/reports/sender?period=this_month')->assertOk();
        $this->getJson('/api/v1/reports/sender/monthly?period=this_month')->assertOk();
        $this->getJson('/api/v1/reports/manager?period=this_month')->assertOk();
    }

    public function test_active_unlimited_and_expired_unlimited_return_consistent_export_flags(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $subscription = BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'paid',
            'access_starts_at' => now()->subDay(),
            'access_ends_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.plan.tier', PlanLimits::UNLIMITED)
            ->assertJsonPath('data.plan.entitlements.basic_pdf', true)
            ->assertJsonPath('data.plan.entitlements.monthly_pdf', true)
            ->assertJsonPath('data.plan.entitlements.custom_date_range', true)
            ->assertJsonPath('data.plan.entitlements.csv_export', true)
            ->assertJsonPath('data.plan.allowances.sender_remittances.limit', null);

        $subscription->forceFill([
            'access_ends_at' => now()->subMinute(),
        ])->save();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.plan.tier', PlanLimits::FREE)
            ->assertJsonPath('data.plan.entitlements.basic_pdf', true)
            ->assertJsonPath('data.plan.entitlements.monthly_pdf', false)
            ->assertJsonPath('data.plan.allowances.sender_remittances.limit', 20);
    }

    public function test_manager_home_uses_actual_payment_date_not_transaction_date(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $customer = $this->customer();

        $localNow = new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Fiji'));
        $lastMonthDate = $localNow->modify('first day of last month')->format('Y-m-d');
        $today = $localNow->format('Y-m-d');

        $tx = $this->transaction($customer['id'], [
            'occurred_at' => $lastMonthDate . ' 09:00',
        ]);

        $this->pay($tx['id'], '1250.25', ['paid_on' => $today])
            ->assertCreated();

        $home = $this->getJson('/api/v1/reports/manager/home')->assertOk()->json('data');

        $this->assertSame('1250.25', $home['sent_this_month_bdt']);
        $this->assertSame([], $home['received_this_month']);
        $this->assertSame('24749.75', $home['still_to_pay_bdt']);

        // This is intentionally a different metric: transaction-cohort totals.
        $this->getJson('/api/v1/reports/manager?period=this_month')
            ->assertOk()
            ->assertJsonPath('data.by_currency', []);
    }

    public function test_manager_home_nets_reversal_entries_without_double_counting(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $customer = $this->customer();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Fiji')))
            ->format('Y-m-d');

        $tx = $this->transaction($customer['id']);
        $payment = $this->pay($tx['id'], '999.99', ['paid_on' => $today])
            ->assertCreated()->json('data.payment');

        $this->getJson('/api/v1/reports/manager/home')
            ->assertOk()->assertJsonPath('data.sent_this_month_bdt', '999.99');

        $this->postJson(
            "/api/v1/manager-transactions/{$tx['id']}/payments/{$payment['id']}/reverse",
            ['id' => $this->uuid()]
        )->assertCreated();

        $this->getJson('/api/v1/reports/manager/home')
            ->assertOk()->assertJsonPath('data.sent_this_month_bdt', '0.00');
    }
}
