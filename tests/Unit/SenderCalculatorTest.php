<?php

namespace Tests\Unit;

use App\Support\Sender\SenderCalculator;
use PHPUnit\Framework\TestCase;

class SenderCalculatorTest extends TestCase
{
    public function test_total_bdt_is_amount_times_rate_rounded_half_up(): void
    {
        $this->assertSame('7250.00', SenderCalculator::totalBdt('100.00', '72.50'));
        $this->assertSame('2406.15', SenderCalculator::totalBdt('50', '48.123'));
        $this->assertSame('33.33', SenderCalculator::totalBdt('1.00', '33.3333'));
        $this->assertSame('0.01', SenderCalculator::totalBdt('0.01', '0.505'));   // 0.00505 rounds up
        $this->assertSame('0.00', SenderCalculator::totalBdt('0.01', '0.4'));
    }

    public function test_report_totals_add_the_rounded_rows_and_keep_currencies_apart(): void
    {
        $rows = [
            ['currency' => 'FJD', 'amount' => '100.00', 'charge' => '2.00', 'total_bdt' => SenderCalculator::totalBdt('100.00', '72.50')],
            ['currency' => 'FJD', 'amount' => '1.00',   'charge' => null,   'total_bdt' => SenderCalculator::totalBdt('1.00', '33.3333')],
            ['currency' => 'FJD', 'amount' => '1.00',   'charge' => '0',    'total_bdt' => SenderCalculator::totalBdt('1.00', '33.3333')],
            ['currency' => 'FJD', 'amount' => '1.00',   'charge' => '0',    'total_bdt' => SenderCalculator::totalBdt('1.00', '33.3333')],
            ['currency' => 'AUD', 'amount' => '50.00',  'charge' => '1.50', 'total_bdt' => SenderCalculator::totalBdt('50', '48.123')],
        ];

        $summary = SenderCalculator::summarize($rows);

        $this->assertSame(5, $summary['count']);
        $this->assertSame('9756.14', $summary['total_bdt']);  // 7250.00 + 3 x 33.33 + 2406.15, the sum of the rounded rows (adding unrounded values would give 9756.15)
        $this->assertSame(['AUD', 'FJD'], array_keys($summary['by_currency']));
        $this->assertSame(['count' => 4, 'sent' => '103.00', 'charges' => '2.00'], $summary['by_currency']['FJD']);
        $this->assertSame(['count' => 1, 'sent' => '50.00', 'charges' => '1.50'], $summary['by_currency']['AUD']);
    }

    public function test_empty_report(): void
    {
        $this->assertSame(['count' => 0, 'total_bdt' => '0.00', 'by_currency' => []], SenderCalculator::summarize([]));
    }
}
