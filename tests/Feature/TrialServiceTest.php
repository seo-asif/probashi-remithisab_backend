<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessSubscription;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanResolver;
use App\Support\Plans\TrialService;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class TrialServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private function membership(
        Business $business,
        BusinessRole $role,
    ): BusinessMembership {
        $user = $this->makeMember($business, $role);

        return BusinessMembership::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    public function test_owner_can_activate_exactly_seven_day_trial(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $subscription = app(TrialService::class)->activate(
            $business,
            $membership,
        );

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'trial',
            $subscription->access_source,
        );

        $this->assertNotNull($subscription->access_starts_at);
        $this->assertNotNull($subscription->access_ends_at);
        $this->assertNotNull($subscription->trial_used_at);

        $this->assertSame(
            7 * 24 * 60 * 60,
            $subscription->access_ends_at->getTimestamp()
             - $subscription->access_starts_at->getTimestamp(),
        );

        $this->assertSame(
            $subscription->access_starts_at->getTimestamp(),
            $subscription->trial_used_at->getTimestamp(),
        );

        $this->assertTrue(
            PlanResolver::isUnlimited(
                $subscription,
                $subscription->access_starts_at,
            )
        );
    }

    public function test_suspended_business_cannot_activate_trial_through_service(): void
    {
        $business = $this->makeBusiness();

        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $business->forceFill([
            'status' => AccountStatus::Suspended,
        ])->save();

        $this->assertDatabaseHas(
            'businesses',
            [
                'id'     => $business->id,
                'status' => AccountStatus::Suspended->value,
            ],
        );

        try {
            app(TrialService::class)->activate(
                $business,
                $membership,
            );

            $this->fail(
                'Suspended business unexpectedly activated the trial.'
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_TRIAL_FORBIDDEN',
                $e->errorCode,
            );

            $this->assertSame(
                403,
                $e->status,
            );
        }

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
            ],
        );
    }

    public function test_unverified_user_cannot_activate_trial_through_service(): void
{
    $business = $this->makeBusiness();

    $membership = $this->membership(
        $business,
        BusinessRole::Owner,
    );

    $user = $membership->user()
        ->firstOrFail();

    $user->forceFill([
        'email_verified_at' => null,
    ])->save();

    $this->assertDatabaseHas(
        'users',
        [
            'id' => $user->id,
            'email_verified_at' => null,
        ],
    );

    try {
        app(TrialService::class)->activate(
            $business,
            $membership,
        );

        $this->fail(
            'Unverified user unexpectedly activated the trial.'
        );
    } catch (ApiException $e) {
        $this->assertSame(
            'PLAN_TRIAL_FORBIDDEN',
            $e->errorCode,
        );

        $this->assertSame(
            403,
            $e->status,
        );
    }

    $this->assertDatabaseMissing(
        'business_subscriptions',
        [
            'business_id' => $business->id,
        ],
    );
}

    public function test_administrator_can_activate_trial(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Administrator,
        );

        $subscription = app(TrialService::class)->activate(
            $business,
            $membership,
        );

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'trial',
            $subscription->access_source,
        );
    }

    public function test_operator_cannot_activate_trial(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Operator,
        );

        try {
            app(TrialService::class)->activate(
                $business,
                $membership,
            );

            $this->fail(
                'Operator unexpectedly activated the trial.'
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_TRIAL_FORBIDDEN',
                $e->errorCode,
            );

            $this->assertSame(403, $e->status);
        }

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
            ],
        );
    }

    public function test_trial_can_only_be_used_once_even_after_it_expires(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $service = app(TrialService::class);

        $first = $service->activate(
            $business,
            $membership,
        );

        $first->forceFill([
            'access_starts_at' => now('UTC')->subDays(8),
            'access_ends_at'   => now('UTC')->subDay(),
        ])->save();

        try {
            $service->activate(
                $business,
                $membership,
            );

            $this->fail(
                'The same account unexpectedly received a second trial.'
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_TRIAL_ALREADY_USED',
                $e->errorCode,
            );

            $this->assertSame(409, $e->status);
        }

        $this->assertSame(
            1,
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->count(),
        );
    }

    public function test_active_unlimited_access_is_not_overwritten_by_trial(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $startsAt = now('UTC')->subDay();
        $endsAt = now('UTC')->addDays(30);

        $existing = BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'manual',
            'access_starts_at' => $startsAt,
            'access_ends_at'   => $endsAt,
            'trial_used_at'    => null,
        ]);

        try {
            app(TrialService::class)->activate(
                $business,
                $membership,
            );

            $this->fail(
                'Active Unlimited access was unexpectedly replaced by a trial.'
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_ALREADY_UNLIMITED',
                $e->errorCode,
            );

            $this->assertSame(409, $e->status);
        }

        $existing->refresh();

        $this->assertSame(
            'manual',
            $existing->access_source,
        );

        $this->assertNull(
            $existing->trial_used_at,
        );

        $this->assertSame(
            $endsAt->getTimestamp(),
            $existing->access_ends_at->getTimestamp(),
        );
    }

    public function test_expired_trial_resolves_back_to_free(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $subscription = app(TrialService::class)->activate(
            $business,
            $membership,
        );

        $afterExpiry = new DateTimeImmutable(
            '@' . (
                $subscription->access_ends_at->getTimestamp() + 1
            )
        );

        $afterExpiry = $afterExpiry->setTimezone(
            new DateTimeZone('UTC')
        );

        $this->assertFalse(
            PlanResolver::isUnlimited(
                $subscription,
                $afterExpiry,
            )
        );
    }

    public function test_expired_paid_access_can_use_trial_if_trial_was_never_used(): void
    {
        $business = $this->makeBusiness();
        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'manual',
            'access_starts_at' => now('UTC')->subDays(40),
            'access_ends_at'   => now('UTC')->subDays(10),
            'trial_used_at'    => null,
        ]);

        $subscription = app(TrialService::class)->activate(
            $business,
            $membership,
        );

        $this->assertSame(
            'trial',
            $subscription->access_source,
        );

        $this->assertNotNull(
            $subscription->trial_used_at,
        );

        $this->assertTrue(
            PlanResolver::isUnlimited(
                $subscription,
                $subscription->access_starts_at,
            )
        );
    }

    public function test_suspended_membership_cannot_activate_trial_through_service(): void
    {
        $business = $this->makeBusiness();

        $membership = $this->membership(
            $business,
            BusinessRole::Owner,
        );

        $membership->forceFill([
            'status' => AccountStatus::Suspended,
        ])->save();

        $this->assertDatabaseHas(
            'business_memberships',
            [
                'id'     => $membership->id,
                'status' => AccountStatus::Suspended->value,
            ],
        );

        try {
            app(TrialService::class)->activate(
                $business,
                $membership,
            );

            $this->fail(
                'Suspended membership unexpectedly activated the trial.'
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_TRIAL_FORBIDDEN',
                $e->errorCode,
            );

            $this->assertSame(
                403,
                $e->status,
            );
        }

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
            ],
        );
    }
}
