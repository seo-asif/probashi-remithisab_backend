<?php

namespace App\Support\Sender;

/**
 * Sender Mode money maths on bcmath strings, never floats.
 * App rule: total BDT = amount x rate. The server rounds each remittance to 2 decimals (half up)
 * and reports add the rounded values, so a report always equals the sum of its rows.
 */
final class SenderCalculator
{
    public static function dec(mixed $value, int $scale): string
    {
        return number_format((float) ($value ?? 0), $scale, '.', '');
    }

    public static function totalBdt(mixed $amount, mixed $rate): string
    {
        return bcadd(bcmul(self::dec($amount, 2), self::dec($rate, 4), 6), '0.005', 2);
    }

    /**
     * Report totals. Sent amounts and charges are kept PER CURRENCY (they cannot be added across
     * currencies); the BDT total adds every remittance.
     *
     * @param iterable<array{currency: string, amount: mixed, charge: mixed, total_bdt: mixed}> $rows
     * @return array{count: int, total_bdt: string, by_currency: array<string, array{count: int, sent: string, charges: string}>}
     */
    public static function summarize(iterable $rows): array
    {
        $out = ['count' => 0, 'total_bdt' => '0.00', 'by_currency' => []];

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];

            $bucket = $out['by_currency'][$currency] ?? ['count' => 0, 'sent' => '0.00', 'charges' => '0.00'];
            $bucket['count']++;
            $bucket['sent']    = bcadd($bucket['sent'], self::dec($row['amount'], 2), 2);
            $bucket['charges'] = bcadd($bucket['charges'], self::dec($row['charge'] ?? 0, 2), 2);

            $out['by_currency'][$currency] = $bucket;
            $out['count']++;
            $out['total_bdt'] = bcadd($out['total_bdt'], self::dec($row['total_bdt'], 2), 2);
        }

        ksort($out['by_currency']);

        return $out;
    }
}
