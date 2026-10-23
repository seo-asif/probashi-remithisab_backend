<?php

namespace App\Support\Money;

/**
 * Validate unsigned decimal inputs against a maximum and a selectable precision.
 * Codes: {name}_required, {name}_invalid, {name}_too_large.
 */
final class MoneyField
{
    public static function check(mixed $value, string $name, string $label, string $max, bool $required = true, bool $positive = true, int $decimals = 2): ?array
    {
        if ($value === null || $value === '') {
            return $required ? ["{$name}_required", "Enter {$label}" . ($positive ? ' greater than 0.' : '.')] : null;
        }

        if (!FinancialNumber::isValid($value, $decimals) || ($positive && bccomp(Dec::of($value, $decimals), '0', $decimals) <= 0)) {
            return ["{$name}_invalid", 'Enter ' . $label . ($positive ? ' greater than 0' : ', 0 or more') . ' (maximum ' . $decimals . ' decimals).'];
        }

        if (bccomp(Dec::of($value, $decimals), $max, $decimals) > 0) {
            return ["{$name}_too_large", ucfirst(preg_replace('/^(an?|the) /', '', $label)) . ' is too large (maximum ' . number_format((float) $max) . ').'];
        }

        return null;
    }
}
