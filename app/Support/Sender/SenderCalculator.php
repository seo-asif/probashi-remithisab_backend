<?php

namespace App\Support\Sender;

use App\Support\Money\Dec;

/**
 * Sender Mode money maths on bcmath strings, never floats.
 * App rule: total BDT = amount x rate. The server rounds each remittance to 2 decimals (half up)
 * and reports add the rounded values, so a report always equals the sum of its rows.
 */
final class SenderCalculator
{

    public static function dec(mixed $value, int $scale): string
    {
        return Dec::of($value, $scale);
    }

    public static function totalBdt(mixed $amount, mixed $rate): string
    {
        return Dec::round(
            bcmul(Dec::of($amount), Dec::of($rate, 4), 8),
            2
        );
    }

    public static function breakdown(
        mixed $amount,
        mixed $rate,
        mixed $charge,
        string $chargeType,
        mixed $incentivePct
    ): array {
        $amount = Dec::of($amount);
        $rate = Dec::of($rate, 4);
        $charge = Dec::of($charge);
        $pct = Dec::of($incentivePct);

        $principal = $chargeType === 'included'
        ? bcsub($amount, $charge, 2)
        : $amount;

        $base = bcmul($principal, $rate, 8);
        $incentive = Dec::percentOf($base, $pct);

        return [
            'principal'     => $principal,
            'base_bdt'      => Dec::round($base, 2),
            'incentive_bdt' => Dec::round($incentive, 2),
            'family_bdt'    => Dec::round(bcadd($base, $incentive, 8), 2),
            'total_cost'    => $chargeType === 'included'
            ? $amount
            : bcadd($amount, $charge, 2),
        ];
    }

    public static function familyBdt(
        mixed $amount,
        mixed $rate,
        mixed $charge,
        string $chargeType,
        mixed $incentivePct
    ): string {
        return self::breakdown(
            $amount,
            $rate,
            $charge,
            $chargeType,
            $incentivePct
        )['family_bdt'];
    }

    public static function summarize(array $rows): array
    {
        $summary = [
            'count'       => 0,
            'total_bdt'   => '0.00',
            'by_currency' => [],
        ];

        foreach ($rows as $row) {
            $currency = $row['currency'];

            if (!isset($summary['by_currency'][$currency])) {
                $summary['by_currency'][$currency] = [
                    'count'   => 0,
                    'sent'    => '0.00',
                    'charges' => '0.00',
                ];
            }

            $summary['count']++;

            // Sum each row's already-rounded BDT amount.
            $summary['total_bdt'] = bcadd(
                $summary['total_bdt'],
                Dec::of($row['total_bdt'], 2),
                2
            );

            $group = &$summary['by_currency'][$currency];

            $group['count']++;

            $group['sent'] = bcadd(
                $group['sent'],
                Dec::of($row['amount'], 2),
                2
            );

            $group['charges'] = bcadd(
                $group['charges'],
                Dec::of($row['charge'] ?? '0', 2),
                2
            );

            unset($group);
        }

        // Keep the currency order predictable.
        ksort($summary['by_currency']);

        return $summary;
    }

}
