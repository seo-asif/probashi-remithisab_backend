<?php

namespace Tests\Feature\Plans;

use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\User;
use App\Models\UserMonthlyUsage;
use App\Support\Plans\PlanQuotaService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class PlanEnforcementTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private Business $business;
    private User $user;
    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->user = $this->actAs($this->business);
        $this->customer = $this->customer();
    }

    public function test_sender_twentieth_succeeds_and_twenty_first_is_blocked(): void
    {
        $this->seedSenderUsage(19);

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload()
        )->assertCreated();

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload()
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_SENDER_MONTHLY_LIMIT')
            ->assertJsonPath('data.limit', 20)
            ->assertJsonPath('data.used', 20)
            ->assertJsonPath('data.remaining', 0);

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => $this->periodStart(),
            'sender_remittances_used' => 20,
        ]);
    }

    public function test_manager_twentieth_succeeds_and_twenty_first_is_blocked(): void
    {
        $this->seedManagerUsage(19);

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload($this->customer['id'])
        )->assertCreated();

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload($this->customer['id'])
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_MANAGER_MONTHLY_LIMIT')
            ->assertJsonPath('data.limit', 20)
            ->assertJsonPath('data.used', 20)
            ->assertJsonPath('data.remaining', 0);

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $this->business->id,
            'period_start' => $this->periodStart(),
            'manager_transactions_used' => 20,
        ]);
    }

    public function test_sender_idempotent_retry_does_not_consume_another_slot(): void
    {
        $this->seedSenderUsage(19);

        $id = $this->uuid();

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload(['id' => $id])
        )
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload([
                'id' => $id,
                'amount' => '1',
            ])
        )
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.amount', '500.00');

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => $this->periodStart(),
            'sender_remittances_used' => 20,
        ]);

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload(['id' => $this->uuid()])
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_SENDER_MONTHLY_LIMIT');
    }

    public function test_manager_idempotent_retry_does_not_consume_another_slot(): void
    {
        $this->seedManagerUsage(19);

        $id = $this->uuid();

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload(
                $this->customer['id'],
                ['id' => $id]
            )
        )
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload(
                $this->customer['id'],
                [
                    'id' => $id,
                    'amount' => '1',
                ]
            )
        )
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.amount', '500.00');

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $this->business->id,
            'period_start' => $this->periodStart(),
            'manager_transactions_used' => 20,
        ]);

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload(
                $this->customer['id'],
                ['id' => $this->uuid()]
            )
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_MANAGER_MONTHLY_LIMIT');
    }

    public function test_sender_and_manager_monthly_quotas_are_independent(): void
    {
        $this->seedSenderUsage(20);
        $this->seedManagerUsage(19);

        $this->postJson(
            '/api/v1/sender-remittances',
            $this->senderPayload()
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_SENDER_MONTHLY_LIMIT');

        $this->postJson(
            '/api/v1/manager-transactions',
            $this->txPayload($this->customer['id'])
        )->assertCreated();

        $this->assertDatabaseHas('user_monthly_usage', [
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => $this->periodStart(),
            'sender_remittances_used' => 20,
        ]);

        $this->assertDatabaseHas('business_monthly_usage', [
            'business_id' => $this->business->id,
            'period_start' => $this->periodStart(),
            'manager_transactions_used' => 20,
        ]);
    }

    private function seedSenderUsage(int $used): void
    {
        UserMonthlyUsage::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'period_start' => $this->periodStart(),
            'sender_remittances_used' => $used,
        ]);
    }

    private function seedManagerUsage(int $used): void
    {
        BusinessMonthlyUsage::create([
            'business_id' => $this->business->id,
            'period_start' => $this->periodStart(),
            'manager_transactions_used' => $used,
        ]);
    }

    private function periodStart(): string
    {
        return app(PlanQuotaService::class)->periodStart(
            $this->business,
            new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC'),
            ),
        );
    }
}