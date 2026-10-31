<?php

namespace Tests\Feature\Plans;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\ManagerSubscription;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Support\Plans\PlanAllowanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndependentPlanAllowanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_plus_and_manager_free_are_independent(): void
    {
        [$business, $user] = $this->context();
        SenderSubscription::create([
            'user_id' => $user->id,
            'tier' => 'plus',
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);
        $plans = app(PlanAllowanceService::class);
        $at = new \DateTimeImmutable('2026-10-20T00:00:00Z');
        $sender = $plans->senderSnapshot($business, $user->id, $at);
        $manager = $plans->managerSnapshot($business, $user->id, $at);
        $this->assertSame('plus', $sender['tier']);
        $this->assertNull($sender['allowances']['family_members']['limit']);
        $this->assertTrue($sender['entitlements']['csv_export']);
        $this->assertSame('free', $manager['tier']);
        $this->assertSame(10, $manager['allowances']['customers']['limit']);
        $this->assertFalse($manager['entitlements']['csv_export']);
    }

    public function test_manager_pro_does_not_unlock_sender(): void
    {
        [$business, $user] = $this->context();
        ManagerSubscription::create([
            'business_id' => $business->id,
            'tier' => 'pro',
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);
        $plans = app(PlanAllowanceService::class);
        $at = new \DateTimeImmutable('2026-10-20T00:00:00Z');
        $this->assertSame(5, $plans->senderSnapshot($business, $user->id, $at)['allowances']['family_members']['limit']);
        $this->assertNull($plans->managerSnapshot($business, $user->id, $at)['allowances']['customers']['limit']);
    }

    public function test_expired_paid_subscription_reverts_to_free(): void
    {
        [$business, $user] = $this->context();
        SenderSubscription::create([
            'user_id' => $user->id,
            'tier' => 'plus',
            'access_starts_at' => '2026-09-01 00:00:00',
            'access_ends_at' => '2026-10-01 00:00:00',
        ]);
        $sender = app(PlanAllowanceService::class)->senderSnapshot($business, $user->id, new \DateTimeImmutable('2026-10-20T00:00:00Z'));
        $this->assertSame('free', $sender['tier']);
        $this->assertSame(20, $sender['allowances']['sender_remittances']['limit']);
        $this->assertFalse($sender['entitlements']['csv_export']);
    }

    public function test_legacy_unlimited_does_not_unlock_independent_snapshots(): void
    {
        [$business, $user] = $this->context();
        BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => 'unlimited',
            'access_source' => 'manual',
        ]);
        $plans = app(PlanAllowanceService::class);
        $this->assertSame('free', $plans->senderSnapshot($business, $user->id)['tier']);
        $this->assertSame('free', $plans->managerSnapshot($business, $user->id)['tier']);
        $this->assertSame(20, $plans->senderSnapshot($business, $user->id)['allowances']['sender_remittances']['limit']);
        $this->assertSame(20, $plans->managerSnapshot($business, $user->id)['allowances']['manager_transactions']['limit']);
    }

    private function context(): array
    {
        $business = Business::create([
            'name' => 'Allowance Test',
            'timezone' => 'Pacific/Fiji',
            'default_currency' => 'FJD',
        ]);
        return [$business, User::factory()->create()];
    }
}
