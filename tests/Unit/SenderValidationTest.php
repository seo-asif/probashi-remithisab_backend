<?php

namespace Tests\Unit;

use App\Support\Sender\SenderValidation;
use App\Support\Time\BusinessClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SenderValidationTest extends TestCase
{
    public function test_family_input_is_cleaned_like_operator_customers(): void
    {
        $clean = SenderValidation::normalizeFamily(['name' => '  Mother  ', 'phone' => '(+880) 1712-345 678', 'notes' => ' hi ', 'relationship' => ' Mother ']);

        $this->assertSame('Mother', $clean['name']);
        $this->assertSame('+8801712345678', $clean['phone']);
        $this->assertSame('hi', $clean['notes']);
        $this->assertSame('Mother', $clean['relationship']);
    }

    public function test_phone_pattern_matches_operator_mode(): void
    {
        $regex = \App\Support\Sender\SenderOptions::PHONE_REGEX;

        $this->assertSame(1, preg_match($regex, '+8801712345678'));
        $this->assertSame(1, preg_match($regex, '01712345678'));
        $this->assertSame(1, preg_match($regex, str_repeat('1', 18)));
        $this->assertSame(0, preg_match($regex, str_repeat('1', 19)));
        $this->assertSame(0, preg_match($regex, '017-123')); // dashes are removed before the check, not by it
        $this->assertSame(0, preg_match($regex, 'abc'));
        $this->assertSame(0, preg_match($regex, '+'));
    }

    public function test_remittance_input_is_cleaned(): void
    {
        $clean = SenderValidation::normalizeRemittance(['currency' => ' fjd ', 'notes' => '  note ']);

        $this->assertSame('FJD', $clean['currency']);
        $this->assertSame('note', $clean['notes']);
    }

    public function test_charge_cannot_exceed_the_amount_sent(): void
    {
        $clock = new BusinessClock('+12:00');
        $now = new DateTimeImmutable('2026-10-12T10:00:00Z');

        $this->assertSame([], SenderValidation::crossErrors(['amount' => '100.00', 'charge' => '100.00'], $clock, $now));
        $this->assertSame([], SenderValidation::crossErrors(['amount' => '100.00', 'charge' => null], $clock, $now));
        $this->assertSame([], SenderValidation::crossErrors(['amount' => '100.00', 'charge' => ''], $clock, $now));
        $this->assertSame(['charge'], array_keys(SenderValidation::crossErrors(['amount' => '100.00', 'charge' => '100.01'], $clock, $now)));
    }

    public function test_the_date_cannot_be_in_the_future_in_the_business_timezone(): void
    {
        $now = new DateTimeImmutable('2026-10-12T20:00:00Z'); // already 13 Oct 08:00 at +12, still 13 Oct 02:00 at +06

        $fiji = new BusinessClock('+12:00');
        $bangladesh = new BusinessClock('+06:00');

        $this->assertSame([], SenderValidation::crossErrors(['remit_date' => '2026-10-13'], $fiji, $now));
        $this->assertSame([], SenderValidation::crossErrors(['remit_date' => '2026-10-12'], $fiji, $now));
        $this->assertSame(['remit_date'], array_keys(SenderValidation::crossErrors(['remit_date' => '2026-10-14'], $fiji, $now)));

        $late = new DateTimeImmutable('2026-10-12T10:00:00Z'); // 12 Oct 22:00 at +12, 12 Oct 16:00 at +06
        $this->assertSame([], SenderValidation::crossErrors(['remit_date' => '2026-10-12'], $bangladesh, $late));
        $this->assertSame(['remit_date'], array_keys(SenderValidation::crossErrors(['remit_date' => '2026-10-13'], $bangladesh, $late)));
    }

    public function test_sender_amount_and_rate_match_prototype_limits(): void
    {
        $atLimit = SenderValidation::moneyErrors([
            'amount'        => '10000.00',
            'rate'          => '400.00',
            'charge'        => '0',
            'charge_type'   => 'included',
            'incentive_pct' => '0',
        ]);

        $this->assertArrayNotHasKey('amount', $atLimit);
        $this->assertArrayNotHasKey('rate', $atLimit);

        $overLimit = SenderValidation::moneyErrors([
            'amount'        => '10000.01',
            'rate'          => '400.01',
            'charge'        => '0',
            'charge_type'   => 'included',
            'incentive_pct' => '0',
        ]);

        $this->assertArrayHasKey('amount', $overLimit);
        $this->assertArrayHasKey('rate', $overLimit);
    }
}
