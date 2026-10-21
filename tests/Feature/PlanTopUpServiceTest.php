<?php

namespace Tests\Feature;

use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanQuotaService;
use App\Support\Plans\PlanTopUpService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PlanTopUpServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_sender_top_up_adds_twenty_only_to_selected_user(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->makeMember($business);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        $usage = app(PlanTopUpService::class)
            ->grantSender(
                $business,
                $user->id,
                $at,
            );

        $this->assertSame(
            PlanTopUpService::TRANSACTION_BONUS,
            $usage->sender_remittance_bonus,
        );

        $this->assertSame(
            20,
            $usage->sender_remittance_bonus,
        );

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id'             => $business->id,
            'user_id'                 => $user->id,
            'period_start'            => '2026-10-01',
            'sender_remittance_bonus' => 20,
        ]);

        $this->assertDatabaseMissing('business_monthly_usage', [
            'business_id'               => $business->id,
            'period_start'              => '2026-10-01',
            'manager_transaction_bonus' => 20,
        ]);
    }

    public function test_manager_top_up_adds_twenty_only_to_business(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->makeMember($business);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        $usage = app(PlanTopUpService::class)
            ->grantManager(
                $business,
                $at,
            );

        $this->assertSame(
            PlanTopUpService::TRANSACTION_BONUS,
            $usage->manager_transaction_bonus,
        );

        $this->assertSame(
            20,
            $usage->manager_transaction_bonus,
        );

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id'               => $business->id,
            'period_start'              => '2026-10-01',
            'manager_transaction_bonus' => 20,
        ]);

        $this->assertDatabaseMissing('user_monthly_usage', [
            'business_id'             => $business->id,
            'user_id'                 => $user->id,
            'period_start'            => '2026-10-01',
            'sender_remittance_bonus' => 20,
        ]);
    }

    public function test_sender_top_up_increases_effective_free_quota_from_twenty_to_forty(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->makeMember($business);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        app(PlanTopUpService::class)
            ->grantSender(
                $business,
                $user->id,
                $at,
            );

        DB::transaction(function () use (
            $business,
            $user,
            $at,
        ): void {
            $usage = app(PlanQuotaService::class)
                ->consumeSenderRemittance(
                    $business,
                    $user->id,
                    $at,
                );

            $this->assertSame(
                1,
                $usage->sender_remittances_used,
            );

            $this->assertSame(
                20,
                $usage->sender_remittance_bonus,
            );

            $this->assertSame(
                PlanLimits::FREE_SENDER_REMITTANCES + 20,
                PlanLimits::FREE_SENDER_REMITTANCES
                 + $usage->sender_remittance_bonus,
            );
        });
    }

    public function test_manager_top_up_increases_effective_free_quota_from_twenty_to_forty(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        app(PlanTopUpService::class)
            ->grantManager(
                $business,
                $at,
            );

        DB::transaction(function () use (
            $business,
            $at,
        ): void {
            $usage = app(PlanQuotaService::class)
                ->consumeManagerTransaction(
                    $business,
                    $at,
                );

            $this->assertSame(
                1,
                $usage->manager_transactions_used,
            );

            $this->assertSame(
                20,
                $usage->manager_transaction_bonus,
            );

            $this->assertSame(
                PlanLimits::FREE_MANAGER_TRANSACTIONS + 20,
                PlanLimits::FREE_MANAGER_TRANSACTIONS
                 + $usage->manager_transaction_bonus,
            );
        });
    }

    public function test_previous_month_sender_bonus_does_not_carry_into_new_month(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->makeMember($business);

        app(PlanTopUpService::class)
            ->grantSender(
                $business,
                $user->id,
                new DateTimeImmutable(
                    '2026-10-31 23:59:00+12:00'
                ),
            );

        DB::transaction(function () use (
            $business,
            $user,
        ): void {
            $usage = app(PlanQuotaService::class)
                ->consumeSenderRemittance(
                    $business,
                    $user->id,
                    new DateTimeImmutable(
                        '2026-11-01 00:01:00+12:00'
                    ),
                );

            $this->assertSame(
                '2026-11-01',
                $usage->period_start->format('Y-m-d'),
            );

            $this->assertSame(
                0,
                $usage->sender_remittance_bonus,
            );

            $this->assertSame(
                1,
                $usage->sender_remittances_used,
            );
        });

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id'             => $business->id,
            'user_id'                 => $user->id,
            'period_start'            => '2026-10-01',
            'sender_remittance_bonus' => 20,
        ]);

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id'             => $business->id,
            'user_id'                 => $user->id,
            'period_start'            => '2026-11-01',
            'sender_remittance_bonus' => 0,
        ]);
    }

    public function test_previous_month_manager_bonus_does_not_carry_into_new_month(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        app(PlanTopUpService::class)
            ->grantManager(
                $business,
                new DateTimeImmutable(
                    '2026-10-31 23:59:00+12:00'
                ),
            );

        DB::transaction(function () use (
            $business,
        ): void {
            $usage = app(PlanQuotaService::class)
                ->consumeManagerTransaction(
                    $business,
                    new DateTimeImmutable(
                        '2026-11-01 00:01:00+12:00'
                    ),
                );

            $this->assertSame(
                '2026-11-01',
                $usage->period_start->format('Y-m-d'),
            );

            $this->assertSame(
                0,
                $usage->manager_transaction_bonus,
            );

            $this->assertSame(
                1,
                $usage->manager_transactions_used,
            );
        });

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id'               => $business->id,
            'period_start'              => '2026-10-01',
            'manager_transaction_bonus' => 20,
        ]);

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id'               => $business->id,
            'period_start'              => '2026-11-01',
            'manager_transaction_bonus' => 0,
        ]);
    }

    public function test_multiple_sender_top_ups_accumulate_in_same_month(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->makeMember($business);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        $service = app(PlanTopUpService::class);

        $service->grantSender(
            $business,
            $user->id,
            $at,
        );

        $usage = $service->grantSender(
            $business,
            $user->id,
            $at,
        );

        $this->assertSame(
            40,
            $usage->sender_remittance_bonus,
        );

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id'             => $business->id,
            'user_id'                 => $user->id,
            'period_start'            => '2026-10-01',
            'sender_remittance_bonus' => 40,
        ]);
    }

    public function test_multiple_manager_top_ups_accumulate_in_same_month(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $at = new DateTimeImmutable(
            '2026-10-20 10:00:00+12:00'
        );

        $service = app(PlanTopUpService::class);

        $service->grantManager(
            $business,
            $at,
        );

        $usage = $service->grantManager(
            $business,
            $at,
        );

        $this->assertSame(
            40,
            $usage->manager_transaction_bonus,
        );

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id'               => $business->id,
            'period_start'              => '2026-10-01',
            'manager_transaction_bonus' => 40,
        ]);
    }
}
