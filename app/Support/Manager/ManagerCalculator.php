<?php

namespace App\Support\Manager;

use App\Support\Money\Dec;

/**
 * Manager maths, Money Rules 3 (exact decimals, half away from zero):
 *   base_bdt        = round2(amount x rate)
 *   obligation_bdt  = base_bdt + withdrawal_charge        ("পাঠাতে হবে"; withdrawal only for Bkash, Nagad, Rocket)
 *   customer_pays   = amount + commission                  (commission is income; the FULL amount is converted)
 *   owed_bdt        = obligation_bdt - paid_bdt
 * Bank remittance:
 *   Other: remit_bdt = the BDT total the operator types
 *   else : remit_bdt = round2(principal x rate x (1 + incentive_pct / 100)), principal = amount - charge if included
 */
final class ManagerCalculator
{
    public static function isWallet(string $method): bool
    {
        return in_array($method, ManagerOptions::WALLET_METHODS, true);
    }

    /** @return array{base_bdt: string, withdrawal_charge: string, obligation_bdt: string, customer_pays: string} */
    public static function transaction(mixed $amount, mixed $rate, string $method, mixed $withdrawalCharge, mixed $commission): array
    {
        $base       = Dec::round(bcmul(Dec::of($amount), Dec::of($rate, 4), 8), 2);
        $withdrawal = self::isWallet($method) ? Dec::of($withdrawalCharge) : '0.00';

        return [
            'base_bdt'          => $base,
            'withdrawal_charge' => $withdrawal,
            'obligation_bdt'    => bcadd($base, $withdrawal, 2),
            'customer_pays'     => bcadd(Dec::of($amount), Dec::of($commission), 2),
        ];
    }

    public static function principal(mixed $amount, mixed $charge, string $chargeType): string
    {
        $amount = Dec::of($amount);

        if ($chargeType !== ManagerOptions::CHARGE_INCLUDED) {
            return $amount;
        }

        $p = bcsub($amount, Dec::of($charge), 2);

        return bccomp($p, '0', 2) > 0 ? $p : '0.00';
    }

    /** @return array{principal: string, base_bdt: string, incentive_bdt: string, remit_bdt: string} */
    public static function remittance(string $method, mixed $amount, mixed $rate, mixed $charge, string $chargeType, bool $incentive, mixed $pct, mixed $typedTotal): array
    {
        if ($method === ManagerOptions::OTHER) {
            $total = Dec::of($typedTotal);

            return ['principal' => '0.00', 'base_bdt' => $total, 'incentive_bdt' => '0.00', 'remit_bdt' => $total];
        }

        $principal = self::principal($amount, $charge, $chargeType);
        $base      = bcmul($principal, Dec::of($rate, 4), 8);
        $extra     = bcdiv(bcmul($base, $incentive ? Dec::of($pct) : '0', 8), '100', 8);

        return [
            'principal'     => $principal,
            'base_bdt'      => Dec::round($base, 2),
            'incentive_bdt' => Dec::round($extra, 2),
            'remit_bdt'     => Dec::round(bcadd($base, $extra, 8), 2),
        ];
    }
}
