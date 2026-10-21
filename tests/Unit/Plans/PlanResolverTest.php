<?php

namespace Tests\Unit\Plans;

use App\Models\BusinessSubscription;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanResolver;
use DateTimeImmutable;
use Tests\TestCase;

class PlanResolverTest extends TestCase
{
    public function test_missing_subscription_is_free(): void
    {
        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveTier(null, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_free_subscription_is_free(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::FREE,
        ]);

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_active_unlimited_subscription_is_unlimited(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-01T00:00:00Z',
            'access_ends_at' => '2026-11-01T00:00:00Z',
        ]);

        $this->assertSame(
            PlanLimits::UNLIMITED,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );

        $this->assertTrue(
            PlanResolver::isUnlimited($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_future_unlimited_subscription_is_not_active_yet(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-21T00:00:00Z',
            'access_ends_at' => '2026-11-21T00:00:00Z',
        ]);

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_unlimited_is_active_exactly_at_start_time(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-20T10:00:00Z',
            'access_ends_at' => '2026-11-20T10:00:00Z',
        ]);

        $this->assertSame(
            PlanLimits::UNLIMITED,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_expired_unlimited_falls_back_to_free(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-09-01T00:00:00Z',
            'access_ends_at' => '2026-10-20T09:59:59Z',
        ]);

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_subscription_is_expired_exactly_at_end_time(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-01T00:00:00Z',
            'access_ends_at' => '2026-10-20T10:00:00Z',
        ]);

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );

        $this->assertFalse(
            PlanResolver::isUnlimited($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    public function test_unlimited_without_dates_remains_unlimited(): void
    {
        $subscription = $this->subscription([
            'tier' => PlanLimits::UNLIMITED,
        ]);

        $this->assertSame(
            PlanLimits::UNLIMITED,
            PlanResolver::effectiveTier($subscription, $this->at('2026-10-20T10:00:00Z'))
        );
    }

    private function subscription(array $attributes): BusinessSubscription
    {
        $subscription = new BusinessSubscription();

        $subscription->forceFill($attributes);

        return $subscription;
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }
}