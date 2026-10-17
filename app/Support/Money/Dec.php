<?php

namespace App\Support\Money;

/**
 * Small exact-decimal helpers on bcmath strings (never floats). Every money value in Phase 3 goes through these.
 * Inputs are already validated to at most 2 decimals, so normalising never loses a digit.
 */
final class Dec
{
    /** Normalises a value to a decimal string with $scale places ("12.5" -> "12.50"). Null and "" become 0. */
    public static function of(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }

        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
            return self::round(trim($value), $scale);
        }

        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return self::round(number_format((float) $value, 10, '.', ''), $scale);
        }

        throw new \InvalidArgumentException('Not a number: ' . var_export($value, true));
    }

    /** Rounds half up (away from zero) to $scale places. */
    public static function round(string $value, int $scale = 2): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';

        return str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $sum, string $v) => bcadd($sum, $v, 8), '0');
    }

    public static function cmp(string $a, string $b, int $scale = 8): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function max(string $a, string $b): string
    {
        return bccomp($a, $b, 8) >= 0 ? $a : $b;
    }

    /** $value x $percent / 100, exact to 8 places. */
    public static function percentOf(string $value, string $percent): string
    {
        return bcdiv(bcmul($value, $percent, 8), '100', 8);
    }

    /** For JSON: always a string with 2 decimals, so the app never sees a float. */
    public static function money(mixed $value): string
    {
        return self::of($value, 2);
    }
}
