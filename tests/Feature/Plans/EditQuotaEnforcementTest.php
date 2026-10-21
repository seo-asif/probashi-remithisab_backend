<?php

namespace Tests\Feature\Plans;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\BusinessSubscription;
use App\Models\User;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class EditQuotaEnforcementTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private Business $business;

    private User $owner;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->owner = $this->actAs($this->business);

        $this->customer = $this->customer();
    }

    public function test_manager_and_sender_edits_share_one_business_allowance_across_users(): void
    {
        $tx = $this->transaction($this->customer['id']);

        $secondUser = $this->makeMember(
            $this->business,
            BusinessRole::Operator,
        );

        $this->login($secondUser);

        $sender = $this->senderRemittance();

        $this->login($this->owner);

        $this->setEditsUsed(19);

        // Manager edit becomes edit #20.
        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}",
            ['rate' => '53'],
        )->assertOk();

        $this->assertEditsUsed(20);

        // A different user in the same business tries edit #21
        // through private Sender Mode.
        $this->login($secondUser);

        $this->patchJson(
            "/api/v1/sender-remittances/{$sender['id']}",
            ['notes' => 'This should be blocked'],
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_EDIT_LIMIT')
            ->assertJsonPath('data.limit', 20)
            ->assertJsonPath('data.used', 20)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.plan', PlanLimits::FREE);

        $this->assertEditsUsed(20);
    }

    public function test_manager_remittance_edit_uses_the_same_shared_allowance(): void
    {
        $remittance = $this->remittance();

        $this->setEditsUsed(19);

        $this->patchJson(
            "/api/v1/manager-remittances/{$remittance['id']}",
            ['notes' => 'Changed once'],
        )->assertOk();

        $this->assertEditsUsed(20);

        $this->patchJson(
            "/api/v1/manager-remittances/{$remittance['id']}",
            ['notes' => 'Blocked edit'],
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_EDIT_LIMIT');

        $this->assertEditsUsed(20);
    }

    public function test_no_op_patches_do_not_use_an_edit_slot_even_when_at_the_limit(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $managerRemittance = $this->remittance();
        $senderRemittance = $this->senderRemittance();

        $this->setEditsUsed(20);

        // Same values already stored: all three are no-ops.
        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}",
            ['rate' => '52'],
        )->assertOk();

        $this->patchJson(
            "/api/v1/manager-remittances/{$managerRemittance['id']}",
            ['amount' => '1000'],
        )->assertOk();

        $this->patchJson(
            "/api/v1/sender-remittances/{$senderRemittance['id']}",
            ['amount' => '500'],
        )->assertOk();

        $this->assertEditsUsed(20);

        $this->assertSame(
            0,
            $this->audits('manager_transaction.updated'),
        );

        $this->assertSame(
            0,
            $this->audits('manager_remittance.updated'),
        );

        $this->assertSame(
            0,
            $this->audits('sender_remittance.updated'),
        );
    }

    public function test_excluded_changes_do_not_use_the_edit_allowance(): void
    {
        $family = $this->recipient([
            'name' => 'Mother One',
            'phone' => '01822222222',
        ]);

        $tx = $this->transaction($this->customer['id']);

        $sender = $this->senderRemittance();

        $this->setEditsUsed(20);

        // Customer edits are excluded.
        $this->patchJson(
            "/api/v1/customers/{$this->customer['id']}",
            ['name' => 'Rahim Changed'],
        )->assertOk();

        // Family-member edits are excluded.
        $this->patchJson(
            "/api/v1/sender-recipients/{$family['id']}",
            ['notes' => 'Updated family note'],
        )->assertOk();

        // Quick Manager status changes are excluded.
        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}/status",
            ['status' => 'currency_pending'],
        )->assertOk();

        // Sender received/not-received toggle is excluded.
        $this->postJson(
            "/api/v1/sender-remittances/{$sender['id']}/received",
            ['received' => true],
        )->assertOk();

        $this->assertEditsUsed(20);
    }

    public function test_rejected_validation_does_not_use_an_edit_slot(): void
    {
        $remittance = $this->remittance();

        $this->setEditsUsed(20);

        // Existing amount is 1000, so this charge is invalid.
        // Validation/business rules must fail before quota consumption.
        $this->patchJson(
            "/api/v1/manager-remittances/{$remittance['id']}",
            ['charge' => '2000'],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error_codes.charge.0',
                'charge_more_than_amount',
            );

        $this->assertEditsUsed(20);
    }

    public function test_unlimited_does_not_block_edits_but_still_records_usage(): void
    {
        $sender = $this->senderRemittance();

        $this->setEditsUsed(20);

        BusinessSubscription::create([
            'business_id' => $this->business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'manual',
        ]);

        $this->patchJson(
            "/api/v1/sender-remittances/{$sender['id']}",
            ['notes' => 'Unlimited edit'],
        )->assertOk();

        // Usage is still recorded so expiry back to Free
        // cannot reset usage already made this month.
        $this->assertEditsUsed(21);
    }

    public function test_previous_month_edit_usage_does_not_block_current_month(): void
    {
        $tx = $this->transaction($this->customer['id']);

        $currentPeriod = $this->periodStart();

        $previousPeriod = (
            new \DateTimeImmutable(
                $currentPeriod,
                new \DateTimeZone('UTC'),
            )
        )
            ->modify('-1 month')
            ->format('Y-m-d');

        BusinessMonthlyUsage::query()->updateOrCreate(
            [
                'business_id' => $this->business->id,
                'period_start' => $previousPeriod,
            ],
            [
                'edits_used' => 20,
            ],
        );

        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}",
            ['rate' => '53'],
        )->assertOk();

        $this->assertDatabaseHas(
            'business_monthly_usage',
            [
                'business_id' => $this->business->id,
                'period_start' => $previousPeriod,
                'edits_used' => 20,
            ],
        );

        $this->assertDatabaseHas(
            'business_monthly_usage',
            [
                'business_id' => $this->business->id,
                'period_start' => $currentPeriod,
                'edits_used' => 1,
            ],
        );
    }

    public function test_edit_usage_rolls_back_with_the_database_transaction(): void
    {
        $period = $this->periodStart();

        try {
            DB::transaction(function () {
                app(PlanQuotaService::class)->consumeEdit(
                    $this->business,
                );

                throw new \RuntimeException(
                    'Force edit quota rollback',
                );
            });
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Force edit quota rollback',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseMissing(
            'business_monthly_usage',
            [
                'business_id' => $this->business->id,
                'period_start' => $period,
            ],
        );
    }

    private function setEditsUsed(int $used): void
    {
        BusinessMonthlyUsage::query()->updateOrCreate(
            [
                'business_id' => $this->business->id,
                'period_start' => $this->periodStart(),
            ],
            [
                'edits_used' => $used,
            ],
        );
    }

    private function assertEditsUsed(int $expected): void
    {
        $this->assertDatabaseHas(
            'business_monthly_usage',
            [
                'business_id' => $this->business->id,
                'period_start' => $this->periodStart(),
                'edits_used' => $expected,
            ],
        );
    }

    private function periodStart(): string
    {
        return app(PlanQuotaService::class)->periodStart(
            $this->business,
            now(),
        );
    }
}