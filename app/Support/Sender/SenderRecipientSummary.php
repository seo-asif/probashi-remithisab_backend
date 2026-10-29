<?php

namespace App\Support\Sender;

use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Support\Money\Dec;
use App\Support\Time\BusinessClock;
use Illuminate\Support\Collection;

/**
 * Aggregate active remittances for only the recipients on the requested page.
 * The model's owner and business scopes remain active on every query.
 */
final class SenderRecipientSummary
{
    /**
     * @param Collection<int, SenderRecipient> $recipients
     * @return array<int, array<string, mixed>> Keyed by internal recipient id (never exposed).
     */
    public function forRecipients(Collection $recipients, BusinessClock $clock): array
    {
        if ($recipients->isEmpty()) {
            return [];
        }

        $ids = $recipients->modelKeys();
        $rows = SenderRemittance::query()
            ->where('recipient_type', SenderOptions::RECIPIENT_FAMILY)
            ->whereIn('recipient_id', $ids)
            ->selectRaw('recipient_id, currency, COUNT(*) AS remittance_count, SUM(amount) AS total_sent, SUM(family_bdt) AS family_bdt, MAX(occurred_at) AS last_sent_at')
            ->groupBy('recipient_id', 'currency')
            ->get();

        $summaries = [];
        foreach ($recipients as $recipient) {
            $summaries[$recipient->getKey()] = [
                'recipient_id' => $recipient->uuid,
                'remittance_count' => 0,
                'by_currency' => [],
                'family_bdt' => '0.00',
                'last_sent_at' => null,
                'last_sent_local' => null,
            ];
        }

        $latest = [];
        foreach ($rows as $row) {
            $id = (int) $row->recipient_id;
            $summary = &$summaries[$id];
            $summary['remittance_count'] += (int) $row->remittance_count;
            $summary['by_currency'][] = [
                'currency' => $row->currency,
                'total_sent' => Dec::money($row->total_sent),
            ];
            $summary['family_bdt'] = bcadd($summary['family_bdt'], Dec::money($row->family_bdt), 2);

            if ($row->last_sent_at !== null) {
                $instant = new \DateTimeImmutable((string) $row->last_sent_at, new \DateTimeZone('UTC'));
                if (!isset($latest[$id]) || $instant > $latest[$id]) {
                    $latest[$id] = $instant;
                    $summary['last_sent_at'] = BusinessClock::utcIso($instant);
                    $summary['last_sent_local'] = $clock->localIso($instant);
                }
            }
            unset($summary);
        }

        foreach ($summaries as &$summary) {
            usort($summary['by_currency'], static fn (array $a, array $b): int => strcmp($a['currency'], $b['currency']));
        }
        unset($summary);

        return $summaries;
    }
}
