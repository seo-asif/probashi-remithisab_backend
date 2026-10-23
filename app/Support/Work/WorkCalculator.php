<?php

namespace App\Support\Work;

use App\Models\WorkSetting;
use App\Support\Money\Dec;

/**
 * Worker maths, Money Rules 4 (exact decimals, half away from zero):
 *   work day:       normal_h = min(hours, day_hours); overtime_h = max(0, hours - day_hours)
 *                   day_pay  = round2(rate x normal_h + rate x ot_multiplier x overtime_h)
 *   holiday / sick: day_pay  = the amount he typed (0 allowed)
 *   day off:        day_pay  = 0
 *   period_pay = sum(day_pay) + sum(bonus) for dates inside the period
 *   loan_left  = sum(take) - sum(cut) - sum(repay)
 *   max_cut    = min(period_pay, loan_left)   (only loan_left when no hourly rate is set)
 *   in_hand    = max(0, period_pay - loan_cut)
 * WorkService resolves each day's effective-dated rate; saved salary pay is a frozen snapshot.
 */
final class WorkCalculator
{
    public const KINDS = ['work', 'holiday', 'sick', 'off'];
    public const PAID_KINDS = ['work', 'holiday', 'sick'];   // days that need a salary
    public const CATEGORIES = ['food', 'grocery', 'rent', 'phone', 'travel', 'medicine', 'drinks', 'smoking', 'lend', 'other'];

    public static function hasRate(?WorkSetting $s): bool
    {
        return $s && $s->hourly_rate !== null && bccomp(Dec::of($s->hourly_rate), '0', 2) > 0;
    }

    /** @return array{normal_h: string, overtime_h: string, day_pay: string} */
    public static function day(string $kind, mixed $hours, mixed $amount, ?WorkSetting $s): array
    {
        if ($kind === 'holiday' || $kind === 'sick') {
            return ['normal_h' => '0.00', 'overtime_h' => '0.00', 'day_pay' => Dec::of($amount)];
        }
        if ($kind !== 'work') {
            return ['normal_h' => '0.00', 'overtime_h' => '0.00', 'day_pay' => '0.00'];
        }

        $hours    = Dec::of($hours);
        $dayHours = Dec::of($s?->day_hours ?? 8);
        $normal   = bccomp($hours, $dayHours, 2) < 0 ? $hours : $dayHours;
        $overtime = bccomp($hours, $dayHours, 2) > 0 ? bcsub($hours, $dayHours, 2) : '0.00';
        $rate     = self::hasRate($s) ? Dec::of($s->hourly_rate) : '0';
        $ot       = Dec::of($s?->ot_multiplier ?? '1.50');

        $pay = bcadd(bcmul($rate, $normal, 8), bcmul(bcmul($rate, $ot, 8), $overtime, 8), 8);

        return ['normal_h' => $normal, 'overtime_h' => $overtime, 'day_pay' => Dec::round($pay, 2)];
    }

    public static function maxCut(string $periodPay, string $loanLeft, bool $hasRate): string
    {
        $left = bccomp($loanLeft, '0', 2) > 0 ? $loanLeft : '0.00';

        if (!$hasRate) {
            return $left;
        }

        return bccomp($periodPay, $left, 2) < 0 ? $periodPay : $left;
    }

    public static function inHand(string $periodPay, string $cut): string
    {
        $v = bcsub($periodPay, $cut, 2);

        return bccomp($v, '0', 2) > 0 ? $v : '0.00';
    }
}
