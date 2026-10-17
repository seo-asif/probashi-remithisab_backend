<?php

namespace Tests\Unit;

use App\Support\Time\BusinessClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Fixed offsets are used so the tests do not depend on timezone database versions. */
class BusinessClockTest extends TestCase
{
    public function test_the_same_instant_gives_the_same_business_time_for_everyone(): void
    {
        $clock   = new BusinessClock('+12:00'); // a Fiji style business
        $instant = new DateTimeImmutable('2026-10-12T10:30:00Z');

        // the viewer's own timezone is irrelevant: the business clock decides
        $fromBangladeshPhone = $instant->setTimezone(new \DateTimeZone('+06:00'));
        $fromFijiPhone       = $instant->setTimezone(new \DateTimeZone('+12:00'));

        $this->assertSame('2026-10-12T22:30:00+12:00', $clock->localIso($fromBangladeshPhone));
        $this->assertSame('2026-10-12T22:30:00+12:00', $clock->localIso($fromFijiPhone));
        $this->assertSame('2026-10-12T10:30:00Z', BusinessClock::utcIso($fromBangladeshPhone));
    }

    public function test_month_key_follows_the_business_timezone_at_the_month_boundary(): void
    {
        $instant = new DateTimeImmutable('2026-10-31T12:30:00Z');

        $this->assertSame('2026-11', (new BusinessClock('+12:00'))->monthKey($instant)); // already November there
        $this->assertSame('2026-10', (new BusinessClock('+06:00'))->monthKey($instant)); // still October there
    }

    public function test_month_bounds_in_utc(): void
    {
        [$start, $end] = (new BusinessClock('+12:00'))->monthBoundsUtc(new DateTimeImmutable('2026-10-31T12:30:00Z'));

        $this->assertSame('2026-10-31T12:00:00Z', BusinessClock::utcIso($start)); // 1 Nov 00:00 at +12
        $this->assertSame('2026-11-30T12:00:00Z', BusinessClock::utcIso($end));   // 1 Dec 00:00 at +12
    }

    public function test_typed_times_are_read_in_the_business_timezone(): void
    {
        $clock = new BusinessClock('+12:00');

        $this->assertSame('2026-10-12T10:30:00Z', BusinessClock::utcIso($clock->parseLocal('2026-10-12 22:30')));
        $this->assertSame('2026-10-12T16:30:00Z', BusinessClock::utcIso($clock->parseLocal('2026-10-12T22:30:00+06:00')));
    }
}
