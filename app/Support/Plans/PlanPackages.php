<?php

namespace App\Support\Plans;

use InvalidArgumentException;

final class PlanPackages
{
    /**
     * Current server-authoritative Unlimited packages.
     *
     * Prices are in BDT.
     */
    private const PACKAGES = [
        1 => 520,
        3 => 1490,
        6 => 2790,
        12 => 5200,
    ];

    private function __construct()
    {
    }

    public static function months(): array
    {
        return array_keys(self::PACKAGES);
    }

    public static function isValidMonths(int $months): bool
    {
        return array_key_exists($months, self::PACKAGES);
    }

    public static function priceBdt(int $months): int
    {
        if (!self::isValidMonths($months)) {
            throw new InvalidArgumentException(
                'Unsupported Unlimited package duration.'
            );
        }

        return self::PACKAGES[$months];
    }
}