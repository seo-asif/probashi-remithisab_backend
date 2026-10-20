<?php

namespace App\Support\Reports;

use App\Support\Validation\Reject;
use App\Support\Time\BusinessClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A report period in the BUSINESS timezone (app: All Time, This Month, Last Month, This Year, Choose Dates).
 * Query: ?period=all|this_month|last_month|this_year  or  ?from=YYYY-MM-DD&to=YYYY-MM-DD (both days included).
 * The same rule is used by list filters (from/to) and every report, so totals always agree.
 */
final class ReportPeriod
{
    public const PERIODS = ['all', 'this_month', 'last_month', 'this_year', 'custom'];

    private function __construct(
        public readonly string $period,
        public readonly ?string $fromDate,          // local date, null = no lower limit
        public readonly ?string $toDate,            // local date, null = no upper limit
        public readonly ?DateTimeImmutable $startUtc,
        public readonly ?DateTimeImmutable $endUtc, // exclusive
    ) {
    }

    public static function fromQuery(array $query, BusinessClock $clock, ?DateTimeImmutable $now = null, string $default = 'all'): self
    {
        $now ??= new DateTimeImmutable('now');
        $from = $query['from'] ?? null;
        $to   = $query['to'] ?? null;

        if (($from !== null && $from !== '') || ($to !== null && $to !== '')) {
            $fromDate = self::date($from, 'from');
            $toDate   = self::date($to, 'to');

            if ($fromDate && $toDate && $fromDate > $toDate) {
                self::fail('to', 'The To date must be on or after the From date.');
            }

            return self::make('custom', $fromDate, $toDate, $clock);
        }

        $period = $query['period'] ?? $default;
        $local  = $clock->toLocal($now);

        return match ($period) {
            'all'        => self::make('all', null, null, $clock),
            'this_month' => self::make('this_month', $local->format('Y-m-01'), $local->format('Y-m-t'), $clock),
            'last_month' => (function () use ($local, $clock) {
                $last = $local->modify('first day of last month');

                return self::make('last_month', $last->format('Y-m-01'), $last->format('Y-m-t'), $clock);
            })(),
            'this_year'  => self::make('this_year', $local->format('Y-01-01'), $local->format('Y-12-31'), $clock),
            default      => self::fail('period', 'Choose All Time, This Month, Last Month, This Year or a date range.'),
        };
    }

    private static function make(string $period, ?string $from, ?string $to, BusinessClock $clock): self
    {
        $zone  = new DateTimeZone($clock->timezone());
        $utc   = new DateTimeZone('UTC');
        $start = $from ? (new DateTimeImmutable($from . ' 00:00:00', $zone))->setTimezone($utc) : null;
        $end   = $to ? (new DateTimeImmutable($to . ' 00:00:00', $zone))->modify('+1 day')->setTimezone($utc) : null;

        return new self($period, $from, $to, $start, $end);
    }

    private static function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            self::fail($field, 'Use a date like 2026-10-15.');
        }

        return $value;
    }

    private static function fail(string $field, string $message): never
    {
        Reject::fail([$field => ['period_invalid', $message]]);
    }

    /** Limits a query on a UTC timestamp column to this period. */
    public function apply(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query, string $column): void
    {
        if ($this->startUtc) {
            $query->where($column, '>=', $this->startUtc->format('Y-m-d H:i:s'));
        }
        if ($this->endUtc) {
            $query->where($column, '<', $this->endUtc->format('Y-m-d H:i:s'));
        }
    }

    public function toArray(): array
    {
        return ['period' => $this->period, 'from' => $this->fromDate, 'to' => $this->toDate];
    }
}
