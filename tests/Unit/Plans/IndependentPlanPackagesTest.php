<?php

namespace Tests\Unit\Plans;

use App\Enums\PlanPaymentProduct;
use App\Support\Plans\IndependentPlanPackages;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndependentPlanPackagesTest extends TestCase
{
    public static function prices(): array
    {
        return [
            'sender 1' => [PlanPaymentProduct::SenderPlus, 1, 99],
            'sender 3' => [PlanPaymentProduct::SenderPlus, 3, 297],
            'sender 6' => [PlanPaymentProduct::SenderPlus, 6, 594],
            'sender 12' => [PlanPaymentProduct::SenderPlus, 12, 1188],
            'manager 1' => [PlanPaymentProduct::ManagerPro, 1, 299],
            'manager 3' => [PlanPaymentProduct::ManagerPro, 3, 897],
            'manager 6' => [PlanPaymentProduct::ManagerPro, 6, 1794],
            'manager 12' => [PlanPaymentProduct::ManagerPro, 12, 3588],
        ];
    }

    #[DataProvider('prices')]
    public function test_authoritative_prices(PlanPaymentProduct $product, int $months, int $expected): void
    {
        $this->assertSame($expected, IndependentPlanPackages::priceBdt($product, $months));
    }

    public function test_legacy_unlimited_cannot_be_priced_as_new_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IndependentPlanPackages::priceBdt(PlanPaymentProduct::Unlimited, 1);
    }

    public function test_topups_cannot_be_priced_as_subscriptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IndependentPlanPackages::priceBdt(PlanPaymentProduct::SenderTopUp, 1);
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IndependentPlanPackages::priceBdt(PlanPaymentProduct::SenderPlus, 2);
    }

    public function test_supported_months_and_products(): void
    {
        $this->assertSame([1, 3, 6, 12], IndependentPlanPackages::months());
        $this->assertSame([PlanPaymentProduct::SenderPlus, PlanPaymentProduct::ManagerPro], IndependentPlanPackages::products());
    }
}
