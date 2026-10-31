<?php

namespace Tests\Unit\Plans;

use App\Models\ManagerSubscription;
use App\Models\SenderSubscription;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanResolver;
use DateTimeImmutable;
use Tests\TestCase;

class IndependentPlanResolverTest extends TestCase
{
    public const SENDER_PLUS = 'plus';

    public const MANAGER_PRO = 'pro';

    public function test_missing_subscriptions_are_free(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(null, $at),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(null, $at),
        );
    }

    public function test_active_sender_plus_is_recognized(): void
    {
        $subscription = $this->sender(
            'plus',
            '2026-10-01T00:00:00Z',
            '2026-11-01T00:00:00Z',
        );

        $this->assertSame(
            PlanLimits::SENDER_PLUS,
            PlanResolver::effectiveSenderTier(
                $subscription,
                $this->at('2026-10-20T10:00:00Z'),
            ),
        );
    }

    public function test_active_manager_pro_is_recognized(): void
    {
        $subscription = $this->manager(
            'pro',
            '2026-10-01T00:00:00Z',
            '2026-11-01T00:00:00Z',
        );

        $this->assertSame(
            PlanLimits::MANAGER_PRO,
            PlanResolver::effectiveManagerTier(
                $subscription,
                $this->at('2026-10-20T10:00:00Z'),
            ),
        );
    }

    public function test_future_subscriptions_are_free(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('plus', '2026-10-21T00:00:00Z', '2026-11-21T00:00:00Z'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('pro', '2026-10-21T00:00:00Z', '2026-11-21T00:00:00Z'),
                $at,
            ),
        );
    }

    public function test_paid_access_begins_exactly_at_start_time(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::SENDER_PLUS,
            PlanResolver::effectiveSenderTier(
                $this->sender('plus', '2026-10-20T10:00:00Z', '2026-11-20T10:00:00Z'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::MANAGER_PRO,
            PlanResolver::effectiveManagerTier(
                $this->manager('pro', '2026-10-20T10:00:00Z', '2026-11-20T10:00:00Z'),
                $at,
            ),
        );
    }

    public function test_paid_access_expires_exactly_at_end_time(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('plus', '2026-09-20T10:00:00Z', '2026-10-20T10:00:00Z'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('pro', '2026-09-20T10:00:00Z', '2026-10-20T10:00:00Z'),
                $at,
            ),
        );
    }

    public function test_expired_subscriptions_are_free(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('plus', '2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('pro', '2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z'),
                $at,
            ),
        );
    }

    public function test_unknown_tiers_are_free(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('unknown'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('unknown'),
                $at,
            ),
        );
    }

    public function test_legacy_unlimited_does_not_unlock_new_plans(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('unlimited'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('unlimited'),
                $at,
            ),
        );
    }

    public function test_sender_and_manager_tiers_cannot_be_interchanged(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveSenderTier(
                $this->sender('pro'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::FREE,
            PlanResolver::effectiveManagerTier(
                $this->manager('plus'),
                $at,
            ),
        );
    }

    public function test_subscriptions_without_dates_follow_legacy_date_semantics(): void
    {
        $at = $this->at('2026-10-20T10:00:00Z');

        $this->assertSame(
            PlanLimits::SENDER_PLUS,
            PlanResolver::effectiveSenderTier(
                $this->sender('plus'),
                $at,
            ),
        );

        $this->assertSame(
            PlanLimits::MANAGER_PRO,
            PlanResolver::effectiveManagerTier(
                $this->manager('pro'),
                $at,
            ),
        );
    }

    private function sender(
        string $tier,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): SenderSubscription {
        return new SenderSubscription([
            'tier' => $tier,
            'access_starts_at' => $startsAt,
            'access_ends_at' => $endsAt,
        ]);
    }

    private function manager(
        string $tier,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): ManagerSubscription {
        return new ManagerSubscription([
            'tier' => $tier,
            'access_starts_at' => $startsAt,
            'access_ends_at' => $endsAt,
        ]);
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
