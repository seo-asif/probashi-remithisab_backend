<?php

namespace Tests\Feature;

use App\Models\BusinessSubscription;
use App\Support\Plans\PaidAccessService;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanPackages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaidAccessServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_server_packages_match_current_unlimited_packages(): void
    {
        $this->assertSame(
            [1, 3, 6, 12],
            PlanPackages::months(),
        );

        $this->assertSame(520, PlanPackages::priceBdt(1));
        $this->assertSame(1490, PlanPackages::priceBdt(3));
        $this->assertSame(2790, PlanPackages::priceBdt(6));
        $this->assertSame(5200, PlanPackages::priceBdt(12));
    }

    public function test_unsupported_package_is_rejected_without_creating_subscription(): void
    {
        $business = $this->makeBusiness();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(PaidAccessService::class)->activate(
                $business,
                2,
            );
        } finally {
            $this->assertDatabaseMissing(
                'business_subscriptions',
                [
                    'business_id' => $business->id,
                ],
            );
        }
    }

    public function test_fresh_paid_activation_starts_from_now(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-20 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        $subscription = app(PaidAccessService::class)
            ->activate($business, 1);

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertSame(
            '2026-10-20 10:00:00',
            $subscription->access_starts_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-11-20 10:00:00',
            $subscription->access_ends_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_month_end_activation_does_not_overflow(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-01-31 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        $subscription = app(PaidAccessService::class)
            ->activate($business, 1);

        $this->assertSame(
            '2026-02-28 10:00:00',
            $subscription->access_ends_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_active_paid_renewal_extends_from_existing_expiry(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-20 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'paid',
            'access_starts_at' => CarbonImmutable::parse(
                '2026-10-01 10:00:00',
                'UTC',
            ),
            'access_ends_at'   => CarbonImmutable::parse(
                '2026-11-30 10:00:00',
                'UTC',
            ),
        ]);

        $subscription = app(PaidAccessService::class)
            ->activate($business, 3);

        $this->assertSame(
            '2026-10-01 10:00:00',
            $subscription->access_starts_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2027-02-28 10:00:00',
            $subscription->access_ends_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_paid_activation_during_trial_starts_from_now_not_trial_expiry(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-20 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        $trialUsedAt = CarbonImmutable::parse(
            '2026-10-18 10:00:00',
            'UTC',
        );

        BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'trial',
            'access_starts_at' => $trialUsedAt,
            'access_ends_at'   => CarbonImmutable::parse(
                '2026-10-25 10:00:00',
                'UTC',
            ),
            'trial_used_at'    => $trialUsedAt,
        ]);

        $subscription = app(PaidAccessService::class)
            ->activate($business, 1);

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertSame(
            '2026-10-20 10:00:00',
            $subscription->access_starts_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-11-20 10:00:00',
            $subscription->access_ends_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-10-18 10:00:00',
            $subscription->trial_used_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_expired_paid_access_restarts_from_now(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-20 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'paid',
            'access_starts_at' => CarbonImmutable::parse(
                '2026-08-01 10:00:00',
                'UTC',
            ),
            'access_ends_at'   => CarbonImmutable::parse(
                '2026-09-01 10:00:00',
                'UTC',
            ),
        ]);

        $subscription = app(PaidAccessService::class)
            ->activate($business, 6);

        $this->assertSame(
            '2026-10-20 10:00:00',
            $subscription->access_starts_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2027-04-20 10:00:00',
            $subscription->access_ends_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }

    public function test_paid_renewal_never_resets_one_time_trial_history(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-20 10:00:00',
                'UTC',
            )
        );

        $business = $this->makeBusiness();

        $trialUsedAt = CarbonImmutable::parse(
            '2026-09-01 08:00:00',
            'UTC',
        );

        BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'paid',
            'access_starts_at' => CarbonImmutable::parse(
                '2026-10-01 10:00:00',
                'UTC',
            ),
            'access_ends_at'   => CarbonImmutable::parse(
                '2026-11-01 10:00:00',
                'UTC',
            ),
            'trial_used_at'    => $trialUsedAt,
        ]);

        $subscription = app(PaidAccessService::class)
            ->activate($business, 12);

        $this->assertNotNull(
            $subscription->trial_used_at,
        );

        $this->assertSame(
            '2026-09-01 08:00:00',
            $subscription->trial_used_at
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s'),
        );
    }
}
