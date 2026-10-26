<?php

namespace App\Support\Reports;

use App\Models\SenderRemittance;
use App\Support\Time\BusinessClock;

/**
 * Sender report for ONE user (runs under BelongsToOwner), Money Rules 2. Per currency and period:
 *   times_sent, total_sent Σ amount, total_cost Σ total_cost, bank_charges Σ charge,
 *   family_bdt Σ family_bdt ("BDT (calculated)"), confirmed_bdt Σ family_bdt where received.
 * BDT figures are added across currencies; foreign amounts never are. by_recipient shows Myself separately.
 * all_received tells the app whether to say "পরিবার পেয়েছে ✓" (every transfer confirmed) or "পরিবার পাবে".
 */
final class SenderReport
{
    public function __construct(private BusinessClock $clock)
    {
    }

    public function totals(ReportPeriod $period): array
    {
        $rows = iterator_to_array($this->rows($period), false);
        $recipients = [];

        foreach ($rows as $r) {
            $key = $r->recipient_type === 'self' ? 'self' : 'family:' . $r->recipient_id;
            $recipients[$key] ??= ['recipient_type' => $r->recipient_type, 'name' => $r->recipient_name, 'times_sent' => 0, 'family_bdt' => '0.00'];
            $recipients[$key]['times_sent']++;
            $recipients[$key]['family_bdt'] = bcadd($recipients[$key]['family_bdt'], (string) $r->family_bdt, 2);
            $recipients[$key]['name'] = $r->recipient_name;
        }
        usort($recipients, fn($a, $b) => bccomp($b['family_bdt'], $a['family_bdt'], 2) ?: strcmp($a['name'], $b['name']));

        return [ ...$period->toArray(), 'timezone' => $this->clock->timezone(), ...self::summary($rows), 'by_recipient' => array_values($recipients)];
    }

    public function monthly(ReportPeriod $period): array
    {
        $months = [];
        foreach ($this->rows($period) as $r) {
            $months[$this->clock->monthKey($r->occurred_at)][] = $r;
        }
        krsort($months);

        $out = [];
        foreach ($months as $month => $rows) {
            $out[] = ['month' => $month, ...self::summary($rows)];
        }

        return [ ...$period->toArray(), 'timezone' => $this->clock->timezone(), 'months' => $out];
    }

    private function rows(ReportPeriod $period): iterable
    {
        $q = SenderRemittance::query()->select([
            'id',
            'recipient_type',
            'recipient_id',
            'recipient_name',
            'currency',
            'amount',
            'charge',
            'total_cost',
            'incentive_bdt',
            'family_bdt',
            'received',
            'occurred_at',
        ]);

        $period->apply($q, 'occurred_at');

        return $q->lazyById(1000);
    }

    private static function summary(array $rows): array
    {
        $byCurrency = [];
        $family = $confirmed = $incentive = '0.00';
        $familyRecipientIds = [];
        $allReceived = true;

        foreach ($rows as $r) {
            $incentive = bcadd($incentive, (string) $r->incentive_bdt, 2);
            $c = $byCurrency[$r->currency] ??= ['currency' => $r->currency, 'times_sent' => 0, 'total_sent' => '0.00', 'total_cost' => '0.00', 'bank_charges' => '0.00', 'family_bdt' => '0.00', 'confirmed_bdt' => '0.00'];
            $c['times_sent']++;
            $c['total_sent'] = bcadd($c['total_sent'], (string) $r->amount, 2);
            $c['total_cost'] = bcadd($c['total_cost'], (string) $r->total_cost, 2);
            $c['bank_charges'] = bcadd($c['bank_charges'], (string) $r->charge, 2);
            $c['family_bdt'] = bcadd($c['family_bdt'], (string) $r->family_bdt, 2);

            if ($r->recipient_type === 'family' && $r->recipient_id !== null) {
                $familyRecipientIds[(string) $r->recipient_id] = true;
            }
            if ($r->received) {
                $c['confirmed_bdt'] = bcadd($c['confirmed_bdt'], (string) $r->family_bdt, 2);
                $confirmed = bcadd($confirmed, (string) $r->family_bdt, 2);
            } else {
                $allReceived = false;
            }
            $family = bcadd($family, (string) $r->family_bdt, 2);
            $byCurrency[$r->currency] = $c;
        }
        ksort($byCurrency);

        return ['times_sent' => count($rows), 'family_bdt' => $family, 'confirmed_bdt' => $confirmed, 'all_received' => $rows !== [] && $allReceived, 'by_currency' => array_values($byCurrency), 'recipient_count' => count($familyRecipientIds),
            'incentive_bdt'      => $incentive];
    }
}
