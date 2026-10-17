<?php

namespace Tests\Unit;

use App\Models\WorkSetting;
use App\Support\Manager\ManagerCalculator;
use App\Support\Money\Dec;
use App\Support\Sender\SenderCalculator;
use App\Support\Sender\SenderValidation;
use App\Support\Work\WorkCalculator;
use PHPUnit\Framework\TestCase;

/** The worked examples of Money Rules v1, section 7. The Flutter app must give exactly the same results. */
class MoneyRulesTest extends TestCase
{
    public function test_s1_sender_excluded_charge(): void
    {
        $this->assertSame(
            ['principal' => '500.00', 'base_bdt' => '25000.00', 'incentive_bdt' => '625.00', 'family_bdt' => '25625.00', 'total_cost' => '510.00'],
            SenderCalculator::breakdown('500', '50', '10', 'excluded', '2.5'),
        );
    }

    public function test_s2_sender_included_charge(): void
    {
        $this->assertSame(
            ['principal' => '490.00', 'base_bdt' => '24500.00', 'incentive_bdt' => '612.50', 'family_bdt' => '25112.50', 'total_cost' => '500.00'],
            SenderCalculator::breakdown('500', '50', '10', 'included', '2.5'),
        );
    }

    public function test_s3_rounding_once_at_the_end(): void
    {
        // base 17,056.4961 · incentive 426.4124025 · family 17,482.91
        $this->assertSame('17482.91', SenderCalculator::familyBdt('333.33', '51.17', '0', 'excluded', '2.5'));
        $this->assertSame('17056.50', SenderCalculator::breakdown('333.33', '51.17', '0', 'excluded', '2.5')['base_bdt']);
    }

    public function test_s4_and_s5_rounding(): void
    {
        $this->assertSame('13087.50', SenderCalculator::familyBdt('250', '52.35', '0', 'excluded', '0'));
        $this->assertSame('10259.74', SenderCalculator::familyBdt('199.99', '50.05', '0', 'excluded', '2.5'));
        $this->assertSame('0.01', Dec::round('0.005'));
        $this->assertSame('-0.01', Dec::round('-0.005'));   // half away from zero
    }

    public function test_s6_to_s10_sender_refusals_and_acceptance(): void
    {
        $ok = ['amount' => '500', 'rate' => '50', 'charge' => '0', 'charge_type' => 'excluded', 'incentive_pct' => '2.5'];

        $this->assertSame('charge_not_less_than_amount', SenderValidation::moneyErrors([...$ok, 'charge' => '500', 'charge_type' => 'included'])['charge'][0]);   // S6
        $this->assertSame([], SenderValidation::moneyErrors([...$ok, 'amount' => '100', 'charge' => '150']));                                                     // S7
        $this->assertSame('250.00', SenderCalculator::breakdown('100', '50', '150', 'excluded', '0')['total_cost']);
        $this->assertSame('incentive_too_high', SenderValidation::moneyErrors([...$ok, 'incentive_pct' => '12'])['incentive_pct'][0]);                          // S8
        $this->assertSame([], SenderValidation::moneyErrors([...$ok, 'incentive_pct' => '10']));
        $this->assertSame('rate_invalid', SenderValidation::moneyErrors([...$ok, 'rate' => '50.255'])['rate'][0]);                                                // S9
        $this->assertSame('amount_invalid', SenderValidation::moneyErrors([...$ok, 'amount' => '0'])['amount'][0]);                                               // S10
        $this->assertSame('rate_invalid', SenderValidation::moneyErrors([...$ok, 'rate' => '0'])['rate'][0]);
        $this->assertSame('charge_too_large', SenderValidation::moneyErrors([...$ok, 'charge' => '10000.01'])['charge'][0]);
    }

    public function test_m1_m2_manager_obligation_and_customer_total(): void
    {
        $this->assertSame('26000.00', ManagerCalculator::transaction('500', '52', 'Bkash', '0', '0')['obligation_bdt']);   // M1
        $m2 = ManagerCalculator::transaction('500', '52', 'Bkash', '0', '5');                                               // M2
        $this->assertSame('505.00', $m2['customer_pays']);
        $this->assertSame('26000.00', $m2['obligation_bdt']);   // the full 500 is converted; commission is income
        $this->assertSame('0.00', ManagerCalculator::transaction('500', '52', 'Bank', '99', '0')['withdrawal_charge']);
    }

    public function test_m14_remittance_incentive(): void
    {
        $this->assertSame('52838.75', ManagerCalculator::remittance('Bank', '1000', '51.55', '0', 'excluded', true, '2.5', null)['remit_bdt']);
        $this->assertSame('51550.00', ManagerCalculator::remittance('Bank', '1000', '51.55', '0', 'excluded', false, '2.5', null)['remit_bdt']);
        $this->assertSame('700.25', ManagerCalculator::remittance('Other', '9', '9', '9', 'included', true, '9', '700.25')['remit_bdt']);
    }

    public function test_w1_w2_w3_worker_maths(): void
    {
        $s = new WorkSetting(['hourly_rate' => '6', 'day_hours' => '8', 'ot_multiplier' => '1.50']);

        $this->assertSame('66.00', WorkCalculator::day('work', '10', null, $s)['day_pay']);                // W1: 6x8 + 6x1.5x2
        $this->assertSame('300.00', WorkCalculator::maxCut('300.00', '1000.00', true));                    // W2
        $this->assertSame('0.00', WorkCalculator::inHand('300.00', '300.00'));
        $this->assertSame('200.00', WorkCalculator::maxCut('300.00', '200.00', true));                     // W3: max 200
        $this->assertSame('1000.00', WorkCalculator::maxCut('0.00', '1000.00', false));                   // no rate: only the loan limits
        $this->assertSame('25.00', WorkCalculator::day('sick', '0', '25', $s)['day_pay']);
        $this->assertSame('0.00', WorkCalculator::day('off', '0', null, $s)['day_pay']);
    }
}
