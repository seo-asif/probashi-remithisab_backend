<?php

namespace App\Support\Money;

/** Foreign-currency limits and exchange-rate precision from prototype v1.1.290. */
final class CurrencyRules
{
    public const CURRENCIES = ['FJD', 'SAR', 'AED', 'QAR', 'KWD', 'OMR', 'BHD', 'MYR', 'SGD', 'KRW', 'JPY', 'USD', 'AUD', 'NZD', 'GBP', 'EUR'];

    private const LIMITS = [
        'FJD' => ['10000', '400', 2],
        'SAR' => ['40000', '400', 2],
        'AED' => ['40000', '400', 2],
        'QAR' => ['40000', '400', 2],
        'KWD' => ['4000', '1000', 2],
        'OMR' => ['5000', '1000', 2],
        'BHD' => ['5000', '1000', 2],
        'MYR' => ['40000', '400', 2],
        'SGD' => ['10000', '400', 2],
        'KRW' => ['15000000', '1', 4],
        'JPY' => ['1500000', '5', 4],
        'USD' => ['10000', '400', 2],
        'AUD' => ['10000', '400', 2],
        'NZD' => ['10000', '400', 2],
        'GBP' => ['10000', '400', 2],
        'EUR' => ['10000', '400', 2],
    ];

    public static function supported(mixed $currency): bool
    {
        return is_string($currency) && array_key_exists($currency, self::LIMITS);
    }

    public static function amountMax(string $currency): string
    {
        return self::LIMITS[$currency][0];
    }

    public static function rateMax(string $currency): string
    {
        return self::LIMITS[$currency][1];
    }

    public static function rateDecimals(string $currency): int
    {
        return self::LIMITS[$currency][2];
    }

    /**
     * Normalize a rate hydrated from DECIMAL(10,4) to the currency's API precision.
     * Only remove insignificant trailing zeros. Never silently round a real rate.
     * This is for stored values only: new client inputs must pass strict validation.
     */
    public static function storedRate(mixed $rate, string $currency): mixed
    {
        if (!self::supported($currency) || !FinancialNumber::isValid($rate, 4)) {
            return $rate;
        }

        $precision = self::rateDecimals($currency);
        $original = Dec::of($rate, 4);
        $normalized = Dec::of($rate, $precision);

        return bccomp($original, $normalized, 4) === 0 ? $normalized : $rate;
    }
}
