<?php

namespace App\Support\Reports;

use App\Models\ManagerRemittance;
use App\Models\ManagerTransaction;
use App\Support\Manager\ManagerOptions as O;
use App\Support\Time\BusinessClock;

/**
 * Manager reports, Money Rules 3.4. Per currency, for the chosen period (business timezone). Deleted records never count.
 *   received            Σ amount (non-pending)          pending              Σ amount (pending)
 *   commission_earned   Σ commission (non-pending)      commission_pending   Σ commission (pending)
 *   bdt_obligations     Σ obligation_bdt (non-pending)  bdt_paid             Σ paid_bdt
 *   bdt_owed            Σ owed_bdt (non-pending)        sent_to_bank         Σ amount of remittances
 *   bank_charges        Σ charge of remittances         remit_bdt            Σ remit_bdt (calculated)
 *   estimated_profit    Σ remit_bdt − Σ bdt_obligations (commission is NOT added; shown separately)
 * BDT figures are also added across currencies in "all_currencies"; foreign amounts never are.
 */
final class ManagerReport
{
    private const BDT = ['bdt_obligations', 'bdt_paid', 'bdt_owed', 'remit_bdt'];

    public function __construct(private BusinessClock $clock)
    {
    }

    public function totals(ReportPeriod $period): array
    {
        $buckets = [];
        $this->walk($period, function (string $month, string $currency, string $kind, object $row) use (&$buckets) {
            $buckets[$currency] ??= self::empty($currency);
            self::add($buckets[$currency], $kind, $row);
        });
        ksort($buckets);

        return [...$period->toArray(), 'timezone' => $this->clock->timezone(), 'by_currency' => array_values(array_map([self::class, 'finish'], $buckets)), 'all_currencies' => self::bdt($buckets)];
    }

    public function monthly(ReportPeriod $period): array
    {
        $months = [];
        $this->walk($period, function (string $month, string $currency, string $kind, object $row) use (&$months) {
            $months[$month][$currency] ??= self::empty($currency);
            self::add($months[$month][$currency], $kind, $row);
        });
        krsort($months);

        $out = [];
        foreach ($months as $month => $buckets) {
            ksort($buckets);
            $out[] = ['month' => $month, 'by_currency' => array_values(array_map([self::class, 'finish'], $buckets)), 'all_currencies' => self::bdt($buckets)];
        }

        return [...$period->toArray(), 'timezone' => $this->clock->timezone(), 'months' => $out];
    }

    /**
     * Manager Home (Money Rules 3.4): this month's tiles, plus "still to pay" over ALL months. No profit on Home.
     */
    public function home(\DateTimeImmutable $now): array
    {
        $month = ReportPeriod::fromQuery(['period' => 'this_month'], $this->clock, $now);
        $tiles = ['received' => [], 'pending' => [], 'commission' => []];
        $sent  = '0.00';

        $q = ManagerTransaction::query()->select(['id', 'currency', 'amount', 'commission', 'status', 'paid_bdt', 'occurred_at']);
        $month->apply($q, 'occurred_at');
        foreach ($q->lazyById(1000) as $t) {
            $key = $t->status === O::STATUS_PENDING ? 'pending' : 'received';
            $tiles[$key][$t->currency] = bcadd($tiles[$key][$t->currency] ?? '0.00', (string) $t->amount, 2);
            if ($key === 'received') {
                $tiles['commission'][$t->currency] = bcadd($tiles['commission'][$t->currency] ?? '0.00', (string) $t->commission, 2);
            }
            $sent = bcadd($sent, (string) $t->paid_bdt, 2);
        }

        $owed = ManagerTransaction::query()->where('status', '<>', O::STATUS_PENDING)->selectRaw('COALESCE(SUM(obligation_bdt - paid_bdt), 0) AS owed')->value('owed');

        $list = fn (array $m) => array_map(fn ($c, $v) => ['currency' => $c, 'amount' => $v], array_keys($m), array_values($m));
        foreach ($tiles as &$m) {
            ksort($m);
        }

        return [
            'month'                => $month->fromDate ? substr($month->fromDate, 0, 7) : null,
            'timezone'             => $this->clock->timezone(),
            'received_this_month'  => $list($tiles['received']),     // গ্রহণ করা পেমেন্ট
            'still_to_pay_bdt'     => bcadd((string) $owed, '0', 2),  // এখনও দিতে হবে (all months)
            'pending_this_month'   => $list($tiles['pending']),      // বাকি গ্রহণ
            'commission_this_month'=> $list($tiles['commission']),   // এই মাসে কমিশন
            'sent_this_month_bdt'  => $sent,                         // এই মাসে পাঠানো
        ];
    }

    private function walk(ReportPeriod $period, callable $each): void
    {
        $tx = ManagerTransaction::query()->select(['id', 'currency', 'amount', 'commission', 'status', 'obligation_bdt', 'paid_bdt', 'occurred_at']);
        $period->apply($tx, 'occurred_at');
        foreach ($tx->lazyById(1000) as $row) {
            $each($this->clock->monthKey($row->occurred_at), $row->currency, 'tx', $row);
        }

        $rem = ManagerRemittance::query()->select(['id', 'currency', 'amount', 'charge', 'remit_bdt', 'occurred_at']);
        $period->apply($rem, 'occurred_at');
        foreach ($rem->lazyById(1000) as $row) {
            $each($this->clock->monthKey($row->occurred_at), $row->currency, 'rem', $row);
        }
    }

    private static function empty(string $currency): array
    {
        return ['currency' => $currency, 'transactions' => 0, 'remittances' => 0, 'received' => '0.00', 'pending' => '0.00',
            'commission_earned' => '0.00', 'commission_pending' => '0.00', 'bdt_obligations' => '0.00', 'bdt_paid' => '0.00',
            'bdt_owed' => '0.00', 'sent_to_bank' => '0.00', 'bank_charges' => '0.00', 'remit_bdt' => '0.00'];
    }

    private static function add(array &$b, string $kind, object $r): void
    {
        if ($kind === 'rem') {
            $b['remittances']++;
            $b['sent_to_bank'] = bcadd($b['sent_to_bank'], (string) $r->amount, 2);
            $b['bank_charges'] = bcadd($b['bank_charges'], (string) $r->charge, 2);
            $b['remit_bdt']    = bcadd($b['remit_bdt'], (string) $r->remit_bdt, 2);

            return;
        }

        $b['transactions']++;
        $b['bdt_paid'] = bcadd($b['bdt_paid'], (string) $r->paid_bdt, 2);

        if ($r->status === O::STATUS_PENDING) {
            $b['pending']            = bcadd($b['pending'], (string) $r->amount, 2);
            $b['commission_pending'] = bcadd($b['commission_pending'], (string) $r->commission, 2);

            return;
        }

        $b['received']          = bcadd($b['received'], (string) $r->amount, 2);
        $b['commission_earned'] = bcadd($b['commission_earned'], (string) $r->commission, 2);
        $b['bdt_obligations']   = bcadd($b['bdt_obligations'], (string) $r->obligation_bdt, 2);
        $b['bdt_owed']          = bcadd($b['bdt_owed'], bcsub((string) $r->obligation_bdt, (string) $r->paid_bdt, 2), 2);
    }

    private static function finish(array $b): array
    {
        $b['estimated_profit'] = bcsub($b['remit_bdt'], $b['bdt_obligations'], 2);   // আনুমানিক লাভ

        return $b;
    }

    private static function bdt(array $buckets): array
    {
        $sum = array_fill_keys(self::BDT, '0.00');
        foreach ($buckets as $b) {
            foreach (self::BDT as $k) {
                $sum[$k] = bcadd($sum[$k], $b[$k], 2);
            }
        }
        $sum['estimated_profit'] = bcsub($sum['remit_bdt'], $sum['bdt_obligations'], 2);

        return $sum;
    }
}
