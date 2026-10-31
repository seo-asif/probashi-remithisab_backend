<?php

namespace App\Support\Plans;

use App\Enums\PlanPaymentProduct;
use InvalidArgumentException;

/**
 * Server-authoritative BDT prices for the new independent subscriptions.
 * No referral discounts or automatic volume discounts apply.
 */
final class IndependentPlanPackages
{
    private const MONTHS = [1, 3, 6, 12];

    private const MONTHLY_BDT = [
        'sender_plus' => 99,
        'manager_pro' => 299,
    ];

    public static function months(): array
    {
        return self::MONTHS;
    }

    public static function isValidMonths(int $months): bool
    {
        return in_array($months, self::MONTHS, true);
    }

    public static function priceBdt(PlanPaymentProduct $product, int $months): int
    {
        if (!self::isValidMonths($months)) {
            throw new InvalidArgumentException('Unsupported subscription duration.');
        }

        $monthly = self::MONTHLY_BDT[$product->value] ?? null;

        if ($monthly === null) {
            throw new InvalidArgumentException('Unsupported independent subscription product.');
        }

        return $monthly * $months;
    }

    public static function products(): array
    {
        return [PlanPaymentProduct::SenderPlus, PlanPaymentProduct::ManagerPro];
    }
}
