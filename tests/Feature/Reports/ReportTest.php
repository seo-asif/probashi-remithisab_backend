<?php

namespace Tests\Feature\Reports;

use App\Enums\BusinessRole;
use App\Models\ManagerRemittance;
use App\Models\ManagerTransaction;
use App\Support\Reports\ManagerReport;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_m11_m13_manager_report_per_currency_with_estimated_profit(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $c = $this->customer();

        // FJD obligations 50,000: 500 x 60 = 30,000 (fully paid) + 400 x 50 = 20,000 (nothing paid)
        $this->transaction($c['id'], ['amount' => '500', 'rate' => '60', 'commission' => '5', 'status' => 'bdt_sent']);
        $this->transaction($c['id'], ['amount' => '400', 'rate' => '50', 'commission' => '4']);
        $this->transaction($c['id'], ['amount' => '30', 'rate' => '50', 'commission' => '1', 'status' => 'currency_pending']);
        // FJD bank remittance 1,000 x 52 = 52,000 (no incentive)
        $this->remittance(['amount' => '1000', 'rate' => '52', 'incentive' => false, 'charge' => '3']);
        // USD: 50 received (M11: never added to FJD)
        $this->transaction($c['id'], ['currency' => 'USD', 'amount' => '50', 'rate' => '120', 'receive_method' => 'Cash']);
        // deleted records never count
        $gone = $this->transaction($c['id'], ['amount' => '999', 'rate' => '50', 'receive_method' => 'Cash']);
        $this->deleteJson("/api/v1/manager-transactions/{$gone['id']}")->assertOk();

        $report = $this->getJson('/api/v1/reports/manager?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
        [$fjd, $usd] = $report['by_currency'];

        $this->assertSame('FJD', $fjd['currency']);
        $this->assertSame('900.00', $fjd['received']);
        $this->assertSame('30.00', $fjd['pending']);
        $this->assertSame('9.00', $fjd['commission_earned']);
        $this->assertSame('1.00', $fjd['commission_pending']);
        $this->assertSame('50000.00', $fjd['bdt_obligations']);
        $this->assertSame('30000.00', $fjd['bdt_paid']);
        $this->assertSame('20000.00', $fjd['bdt_owed']);
        $this->assertSame('1000.00', $fjd['sent_to_bank']);
        $this->assertSame('3.00', $fjd['bank_charges']);
        $this->assertSame('52000.00', $fjd['remit_bdt']);
        $this->assertSame('2000.00', $fjd['estimated_profit']);   // M13: 2,000, not 22,000

        $this->assertSame('50.00', $usd['received']);              // M11: shown side by side
        $this->assertSame('-6000.00', $usd['estimated_profit']);
        $this->assertSame('-4000.00', $report['all_currencies']['estimated_profit']);
    }

    public function test_m12_home_tiles_have_no_profit(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($business);
        $c  = $this->customer();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Fiji')))->modify('-1 minute')->format('Y-m-d H:i');
        $tx = $this->transaction($c['id'], ['commission' => '5', 'occurred_at' => $now]);           // M1: 26,000
        $this->pay($tx['id'], '13000', ['paid_on' => substr($now, 0, 10)])->assertCreated();
        $this->transaction($c['id'], ['status' => 'currency_pending', 'amount' => '20', 'occurred_at' => $now]);

        $home = $this->getJson('/api/v1/reports/manager/home')->assertOk()->json('data');

        $this->assertSame('13000.00', $home['still_to_pay_bdt']);
        $this->assertSame([['currency' => 'FJD', 'amount' => '5.00']], $home['commission_this_month']);
        $this->assertSame([['currency' => 'FJD', 'amount' => '500.00']], $home['received_this_month']);
        $this->assertSame([['currency' => 'FJD', 'amount' => '20.00']], $home['pending_this_month']);
        $this->assertSame('13000.00', $home['sent_this_month_bdt']);
        $this->assertArrayNotHasKey('estimated_profit', $home);
    }

    public function test_d2_report_totals_equal_the_sum_of_their_entries(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($business);
        $c = $this->customer();
        foreach ([['100', '50.05'], ['333.33', '51.17'], ['199.99', '50.05'], ['250', '52.35']] as $i => [$a, $r]) {
            $tx = $this->transaction($c['id'], ['amount' => $a, 'rate' => $r, 'occurred_at' => "2026-09-1{$i} 10:00"]);
            $this->pay($tx['id'], '1000.01');
            $this->remittance(['amount' => $a, 'rate' => $r, 'occurred_at' => "2026-09-1{$i} 11:00"]);
        }

        $report = $this->getJson('/api/v1/reports/manager?period=all')->json('data.by_currency.0');
        $sum = fn (string $model, string $col) => app(CurrentBusiness::class)->runAs($business, fn () => number_format((float) $model::query()->sum($col), 2, '.', ''));

        $this->assertSame($sum(ManagerTransaction::class, 'obligation_bdt'), $report['bdt_obligations']);
        $this->assertSame($sum(ManagerTransaction::class, 'paid_bdt'), $report['bdt_paid']);
        $this->assertSame($sum(ManagerRemittance::class, 'remit_bdt'), $report['remit_bdt']);
        $this->assertSame('4000.04', $report['bdt_paid']);

        // the monthly report adds up to the same totals
        $months = $this->getJson('/api/v1/reports/manager/monthly?period=all')->json('data.months');
        $this->assertSame($report['remit_bdt'], $months[0]['by_currency'][0]['remit_bdt']);
    }

    public function test_months_follow_the_business_timezone(): void
    {
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $c = $this->customer();
        $this->transaction($c['id'], ['amount' => '10', 'rate' => '50', 'occurred_at' => '2026-10-01 00:30']);   // still 30 Sep in UTC
        $this->transaction($c['id'], ['amount' => '20', 'rate' => '50', 'occurred_at' => '2026-09-30 23:30']);

        $months = $this->getJson('/api/v1/reports/manager/monthly?from=2026-09-01&to=2026-10-31')->json('data.months');
        $this->assertSame(['2026-10', '2026-09'], array_column($months, 'month'));
        $this->assertSame('10.00', $months[0]['by_currency'][0]['received']);
        $this->getJson('/api/v1/reports/manager?period=forever')->assertStatus(422)->assertJsonPath('error_codes.period.0', 'period_invalid');
    }

    public function test_sender_report_with_confirmed_bdt_and_myself_separately(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business, BusinessRole::Operator);

        $mother = $this->recipient();
        $self   = $this->senderRemittance();                                                                   // S1: family 25,625, cost 510
        $this->senderRemittance(['recipient_type' => 'family', 'recipient_id' => $mother['id'], 'charge_type' => 'included']);   // S2: 25,112.50
        $this->senderRemittance(['currency' => 'USD', 'amount' => '100', 'rate' => '120', 'charge' => '0', 'incentive_pct' => '0']);   // 12,000
        $this->senderRemittance(['occurred_at' => '2026-08-10 10:00']);                                         // August
        $this->postJson("/api/v1/sender-remittances/{$self['id']}/received", ['received' => true])->assertOk();

        $r = $this->getJson('/api/v1/reports/sender?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');

        $this->assertSame(3, $r['times_sent']);
        $this->assertSame('62737.50', $r['family_bdt']);
        $this->assertSame('25625.00', $r['confirmed_bdt']);
        $this->assertFalse($r['all_received']);
        $this->assertSame(['FJD', 'USD'], array_column($r['by_currency'], 'currency'));
        $this->assertSame(['currency' => 'FJD', 'times_sent' => 2, 'total_sent' => '1000.00', 'total_cost' => '1010.00', 'bank_charges' => '20.00', 'family_bdt' => '50737.50', 'confirmed_bdt' => '25625.00'], $r['by_currency'][0]);
        $this->assertSame(['self', 'family'], array_column($r['by_recipient'], 'recipient_type'));
        $this->assertSame('37625.00', $r['by_recipient'][0]['family_bdt']);

        $months = $this->getJson('/api/v1/reports/sender/monthly?period=all')->json('data.months');
        $this->assertSame(['2026-09', '2026-08'], array_column($months, 'month'));

        $this->actAs($business, BusinessRole::Owner);
        $this->getJson('/api/v1/reports/sender?period=all')->assertJsonPath('data.times_sent', 0);
    }
}
