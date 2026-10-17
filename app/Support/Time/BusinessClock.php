<?php

namespace App\Support\Time;

use App\Models\Business;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One timezone per business, so a user logging in from Bangladesh and from Fiji sees exactly
 * the same transaction times.
 *
 *  - The database stores instants in UTC.
 *  - The API returns every time as UTC ("...Z"), and the app shows it in the BUSINESS timezone,
 *    never the phone's timezone.
 *  - Times typed by the user are read in the business timezone.
 *  - Monthly usage periods are calendar months in the business timezone.
 */
final class BusinessClock
{
    private DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function for(Business $business): self
    {
        return new self($business->timezone);
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }

    public function toLocal(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone($this->zone);
    }

    /** e.g. 2026-10-12T22:30:00+12:00 */
    public function localIso(DateTimeInterface $instant): string
    {
        return $this->toLocal($instant)->format('c');
    }

    /** e.g. 2026-10-12T10:30:00Z */
    public static function utcIso(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** A time without an offset is read in the business timezone. A time with an offset keeps its offset. */
    public function parseLocal(string $text): DateTimeImmutable
    {
        return new DateTimeImmutable($text, $this->zone);
    }

    /** Usage period key, for example "2026-10". */
    public function monthKey(DateTimeInterface $instant): string
    {
        return $this->toLocal($instant)->format('Y-m');
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} UTC [start, start of next month) of the local month */
    public function monthBoundsUtc(DateTimeInterface $instant): array
    {
        $start = $this->toLocal($instant)->modify('first day of this month')->setTime(0, 0, 0);
        $next  = $start->modify('+1 month');
        $utc   = new DateTimeZone('UTC');

        return [$start->setTimezone($utc), $next->setTimezone($utc)];
    }
}
