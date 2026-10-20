<?php

namespace App\Support\Records;

use App\Support\Sender\SenderValidation;
use App\Support\Time\BusinessClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * occurred_at = the date and time the person chose. Stored as UTC PLUS the timezone it was entered in
 * (Money Rules 5). A time without an offset is read in that timezone. Reports and screens still use the
 * business timezone, so every user of a business sees the same days and months.
 */
final class RecordTime
{
    /**
     * @return array{0: ?DateTimeImmutable, 1: string, 2: ?array{0: string, 1: string}} [UTC instant, timezone, error]
     */
    public static function parse(mixed $text, mixed $timezone, string $businessTimezone): array
    {
        $zone = is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : $businessTimezone;

        if ($timezone !== null && $timezone !== '' && $zone !== $timezone) {
            return [null, $zone, ['timezone_invalid', 'Send a valid timezone, for example Pacific/Fiji or Asia/Dhaka.']];
        }

        $when = SenderValidation::parseLocalDateTime($text, new BusinessClock($zone));

        if ($when === null) {
            return [null, $zone, ['date_invalid', 'Choose a valid date and time.']];
        }

        if ($when > new DateTimeImmutable('now')) {
            return [null, $zone, ['date_in_future', 'Date cannot be in the future.']];
        }

        return [$when->setTimezone(new DateTimeZone('UTC')), $zone, null];
    }

    /** A local date (YYYY-MM-DD), not in the future in the given timezone. */
    public static function date(mixed $text, string $timezone, bool $allowFuture = false): ?array
    {
        if (!is_string($text) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['date_invalid', 'Choose a valid date.'];
        }

        if (!$allowFuture && $text > (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d')) {
            return ['date_in_future', 'Date cannot be in the future.'];
        }

        return null;
    }
}
