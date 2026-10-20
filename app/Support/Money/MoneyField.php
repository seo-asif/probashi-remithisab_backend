<?php

namespace App\Support\Money;

/**
 * One check for every money-like field: a plain number (no sign, no exponent), at most 2 decimals (Money Rules 1:
 * a 3rd decimal is refused, also for rates), within limits. Returns [code, English] or null.
 * Codes: {name}_required, {name}_invalid, {name}_too_large.
 */
final class MoneyField
{
    public static function check(mixed $value, string $name, string $label, string $max, bool $required = true, bool $positive = true): ?array
    {
        if ($value === null || $value === '') {
            return $required ? ["{$name}_required", "Enter {$label}" . ($positive ? ' greater than 0.' : '.')] : null;
        }

        if (!FinancialNumber::isValid($value, 2) || ($positive && bccomp(Dec::of($value), '0', 2) <= 0)) {
            return ["{$name}_invalid", 'Enter ' . $label . ($positive ? ' greater than 0' : ', 0 or more') . ' (maximum 2 decimals).'];
        }

        if (bccomp(Dec::of($value), $max, 2) > 0) {
            return ["{$name}_too_large", ucfirst(preg_replace('/^(an?|the) /', '', $label)) . ' is too large (maximum ' . number_format((float) $max) . ').'];
        }

        return null;
    }
}
