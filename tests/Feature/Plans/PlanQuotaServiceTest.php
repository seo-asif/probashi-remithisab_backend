<?php

namespace Tests\Feature\Plans;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\BusinessSubscription;
use App\Models\User;
use App\Models\UserMonthlyUsage;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanQuotaService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PlanQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanQuotaService $quota;
    private Business $business;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quota = app(PlanQuotaService::class);

        $this->business = Business::create([
            'name' => 'Quota Test',
            'timezone' => 'Pacific/Fiji',
            'default_currency' => 'FJD',
        ]);

        $this->user = User::factory()->create();
    }

    public function test_period_uses_business_timezone(): void
    {
        $at = new DateTimeImmutable('2026-10-31T12:30:00Z');

        $this->assertSame(
            '2026-11-01',
            $this->quota->periodStart($this->business, $at)
        );
    }

public function test_quota_consumption_rolls_back_with_the_transaction(): void
{
    try {
        DB::transaction(function () {
            $this->quota->consumeSenderRemittance(
                $this->business,
                $this->user->id,
                $this->at(),
            );

            throw new \RuntimeException('Force rollback');
        });
    } catch (\RuntimeException $e) {
        $this->assertSame('Force rollback', $e->getMessage());
    }

    $this->assertDatabaseMissing('user_monthly_usage', [
        'business_id' => $this->business->id,
        'user_id' => $this->user->id,
        'period_start' => '2026-10-01',
    ]);
}

    public function test_sender_free_limit_allows_twentieth_and_blocks_twenty_first(): void
    {
        UserMonthlyUsage::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 19,
        ]);

        DB::transaction(function () {
            $usage = $this->quota->consumeSenderRemittance(
                $this->business,
                $this->user->id,
                $this->at(),
            );

            $this->assertSame(20, $usage->sender_remittances_used);
        });

        try {
            DB::transaction(function () {
                $this->quota->consumeSenderRemittance(
                    $this->business,
                    $this->user->id,
                    $this->at(),
                );
            });

            $this->fail('Expected Sender quota exception.');
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_SENDER_MONTHLY_LIMIT',
                $e->errorCode
            );

            $this->assertSame(20, $e->data['limit']);
            $this->assertSame(20, $e->data['used']);
            $this->assertSame(0, $e->data['remaining']);
            $this->assertSame('2026-11-01', $e->data['resets_on']);
        }
    }

    public function test_sender_bonus_extends_the_free_limit(): void
    {
        UserMonthlyUsage::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 20,
            'sender_remittance_bonus' => 20,
        ]);

        DB::transaction(function () {
            $usage = $this->quota->consumeSenderRemittance(
                $this->business,
                $this->user->id,
                $this->at(),
            );

            $this->assertSame(21, $usage->sender_remittances_used);
        });
    }

    public function test_manager_quota_is_business_scoped(): void
    {
        BusinessMonthlyUsage::create([
            'business_id' => $this->business->id,
            'period_start' => '2026-10-01',
            'manager_transactions_used' => 19,
        ]);

        DB::transaction(function () {
            $usage = $this->quota->consumeManagerTransaction(
                $this->business,
                $this->at(),
            );

            $this->assertSame(20, $usage->manager_transactions_used);
        });

        try {
            DB::transaction(function () {
                $this->quota->consumeManagerTransaction(
                    $this->business,
                    $this->at(),
                );
            });

            $this->fail('Expected Manager quota exception.');
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_MANAGER_MONTHLY_LIMIT',
                $e->errorCode
            );
        }
    }

    public function test_edit_quota_is_shared_at_business_level(): void
    {
        BusinessMonthlyUsage::create([
            'business_id' => $this->business->id,
            'period_start' => '2026-10-01',
            'edits_used' => 19,
        ]);

        DB::transaction(function () {
            $usage = $this->quota->consumeEdit(
                $this->business,
                $this->at(),
            );

            $this->assertSame(20, $usage->edits_used);
        });

        try {
            DB::transaction(function () {
                $this->quota->consumeEdit(
                    $this->business,
                    $this->at(),
                );
            });

            $this->fail('Expected edit quota exception.');
        } catch (ApiException $e) {
            $this->assertSame('PLAN_EDIT_LIMIT', $e->errorCode);
        }
    }

    public function test_unlimited_does_not_block_but_still_records_usage(): void
    {
        BusinessSubscription::create([
            'business_id' => $this->business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_starts_at' => '2026-10-01 00:00:00',
            'access_ends_at' => '2026-11-01 00:00:00',
        ]);

        UserMonthlyUsage::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => '2026-10-01',
            'sender_remittances_used' => 20,
        ]);

        DB::transaction(function () {
            $usage = $this->quota->consumeSenderRemittance(
                $this->business,
                $this->user->id,
                $this->at(),
            );

            $this->assertSame(21, $usage->sender_remittances_used);
        });
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-20T10:00:00Z');
    }
}