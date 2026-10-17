<?php

namespace App\Support\Money;

/**
 * The app's financialNumber(): digits with an optional decimal part. No sign, no exponent,
 * no spaces inside, no thousands separators. Used for every money and rate field.
 */
final class FinancialNumber
{
    public static function isValid(mixed $value, ?int $maxDecimals = null): bool
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return false;
        }

        if ($text === '' || !preg_match('/^\d+(\.\d+)?$/', $text)) {
            return false;
        }

        $decimals = str_contains($text, '.') ? strlen(explode('.', $text)[1]) : 0;

        return $maxDecimals === null || $decimals <= $maxDecimals;
    }
}
