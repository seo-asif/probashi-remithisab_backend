<?php

namespace Tests\Feature\Plans;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\BusinessSubscription;
use App\Models\ManagerSubscription;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Models\UserMonthlyUsage;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanQuotaService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndependentSubscriptionQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    private PlanQuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Independent Quota Test',
            'timezone' => 'Pacific/Fiji',
            'default_currency' => 'FJD',
        ]);

        $this->user = User::factory()->create();
        $this->quota = app(PlanQuotaService::class);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-20T10:00:00Z');
    }

    private function seedUsage(): void
    {
        UserMonthlyUsage::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 20,
        ]);

        BusinessMonthlyUsage::create([
            'business_id' => $this->business->id,
            'period_start' => '2026-10-01',
            'manager_transactions_used' => 20,
        ]);
    }

    private function senderPlus(): SenderSubscription
    {
        return SenderSubscription::create([
            'user_id' => $this->user->id,
            'tier' => PlanLimits::SENDER_PLUS,
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);
    }

    private function managerPro(): ManagerSubscription
    {
        return ManagerSubscription::create([
            'business_id' => $this->business->id,
            'tier' => PlanLimits::MANAGER_PRO,
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);
    }

    private function consumeSender(): int
    {
        return DB::transaction(
            fn () => $this->quota->consumeSenderRemittance(
                $this->business,
                $this->user->id,
                $this->at()
            )->sender_remittances_used
        );
    }

    private function consumeManager(): int
    {
        return DB::transaction(
            fn () => $this->quota->consumeManagerTransaction(
                $this->business,
                $this->at()
            )->manager_transactions_used
        );
    }

    private function expectLimit(string $code, callable $action, int $expectedUsed = 20): void
    {
        try {
            $action();
            $this->fail("Expected {$code}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame(20, $e->data['limit']);
            $this->assertSame($expectedUsed, $e->data['used']);
        }
    }

    public function test_sender_plus_does_not_unlock_manager(): void
    {
        $this->seedUsage();
        $this->senderPlus();

        $this->assertSame(21, $this->consumeSender());

        $this->expectLimit(
            'PLAN_MANAGER_MONTHLY_LIMIT',
            fn () => $this->consumeManager()
        );

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $this->business->id,
            'manager_transactions_used' => 20,
        ]);
    }

    public function test_manager_pro_does_not_unlock_sender(): void
    {
        $this->seedUsage();
        $this->managerPro();

        $this->assertSame(21, $this->consumeManager());

        $this->expectLimit(
            'PLAN_SENDER_MONTHLY_LIMIT',
            fn () => $this->consumeSender()
        );

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'sender_remittances_used' => 20,
        ]);
    }

    public function test_legacy_unlimited_unlocks_neither_mode(): void
    {
        $this->seedUsage();

        BusinessSubscription::create([
            'business_id' => $this->business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);

        $this->expectLimit(
            'PLAN_SENDER_MONTHLY_LIMIT',
            fn () => $this->consumeSender()
        );

        $this->expectLimit(
            'PLAN_MANAGER_MONTHLY_LIMIT',
            fn () => $this->consumeManager()
        );
    }

    public function test_expired_sender_plus_returns_to_free_quota(): void
    {
        $this->seedUsage();
        $subscription = $this->senderPlus();

        $this->assertSame(21, $this->consumeSender());

        $subscription->forceFill([
            'access_ends_at' => '2026-10-19 00:00:00',
        ])->save();

        $this->expectLimit(
            'PLAN_SENDER_MONTHLY_LIMIT',
            fn () => $this->consumeSender(), 21
        );

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'sender_remittances_used' => 21,
        ]);
    }

    public function test_expired_manager_pro_returns_to_free_quota(): void
    {
        $this->seedUsage();
        $subscription = $this->managerPro();

        $this->assertSame(21, $this->consumeManager());

        $subscription->forceFill([
            'access_ends_at' => '2026-10-19 00:00:00',
        ])->save();

        $this->expectLimit(
            'PLAN_MANAGER_MONTHLY_LIMIT',
            fn () => $this->consumeManager(), 21
        );

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $this->business->id,
            'manager_transactions_used' => 21,
        ]);
    }

    public function test_both_paid_modes_track_usage_independently(): void
    {
        $this->seedUsage();
        $this->senderPlus();
        $this->managerPro();

        $this->assertSame(21, $this->consumeSender());
        $this->assertSame(21, $this->consumeManager());
    }
}
