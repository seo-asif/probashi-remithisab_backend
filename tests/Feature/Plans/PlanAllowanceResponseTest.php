<?php

namespace Tests\Feature\Plans;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\BusinessSubscription;
use App\Models\Customer;
use App\Models\SenderRecipient;
use App\Models\User;
use App\Models\UserMonthlyUsage;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanQuotaService;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class PlanAllowanceResponseTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_free_account_gets_default_allowances_without_creating_usage_rows(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->actAs($business);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.plan.tier', PlanLimits::FREE)
            ->assertJsonPath('data.plan.access_source', null)
            ->assertJsonPath(
                'data.plan.entitlements.monthly_pdf',
                false,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.scope',
                'user',
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.type',
                'monthly',
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.used',
                0,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.remaining',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.family_members.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.limit',
                10,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.limit',
                20,
            );

        // Reading /me must never create quota/subscription state.
        $this->assertDatabaseCount('business_subscriptions', 0);
        $this->assertDatabaseCount('business_monthly_usage', 0);
        $this->assertDatabaseCount('user_monthly_usage', 0);
    }

    public function test_monthly_usage_is_private_for_sender_but_shared_for_manager_and_edits(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $first = $this->actAs($business);

        $second = $this->makeMember(
            $business,
            BusinessRole::Operator,
        );

        $period = $this->periodStart($business);

        UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $first->id,
            'period_start' => $period,
            'sender_remittances_used' => 7,
            'sender_remittance_bonus' => 5,
        ]);

        UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $second->id,
            'period_start' => $period,
            'sender_remittances_used' => 2,
            'sender_remittance_bonus' => 0,
        ]);

        BusinessMonthlyUsage::create([
            'business_id' => $business->id,
            'period_start' => $period,
            'manager_transactions_used' => 8,
            'manager_transaction_bonus' => 20,
            'edits_used' => 4,
        ]);

        // First user has 20 + 5 Sender allowance.
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.limit',
                25,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.used',
                7,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.remaining',
                18,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.bonus',
                5,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.limit',
                40,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.used',
                8,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.remaining',
                32,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.used',
                4,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.remaining',
                16,
            );

        // Same business, different user:
        // Sender usage changes, Manager/edit usage does not.
        $this->login($second);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.used',
                2,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.remaining',
                18,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.limit',
                40,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.used',
                8,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.used',
                4,
            );
    }

    public function test_active_family_count_is_per_user_and_customer_count_is_per_business(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $first = $this->actAs($business);

        $second = $this->makeMember(
            $business,
            BusinessRole::Operator,
        );

        $this->seedFamily($business, $first, 3);
        $this->seedFamily($business, $second, 2);
        $this->seedCustomers($business, 4);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.allowances.family_members.used',
                3,
            )
            ->assertJsonPath(
                'data.plan.allowances.family_members.remaining',
                17,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.used',
                4,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.remaining',
                6,
            );

        $this->login($second);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.allowances.family_members.used',
                2,
            )
            ->assertJsonPath(
                'data.plan.allowances.family_members.remaining',
                18,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.used',
                4,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.remaining',
                6,
            );
    }

    public function test_archived_family_and_customers_still_count_against_creation_cap(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->actAs($business);

        $family = $this->seedFamily(
            $business,
            $user,
            2,
        );

        $customers = $this->seedCustomers(
            $business,
            2,
        );

        app(CurrentBusiness::class)->runAsUser(
            $business,
            $user->id,
            function () use ($family) {
                $family[0]->delete();
            },
        );

        app(CurrentBusiness::class)->runAs(
            $business,
            function () use ($customers) {
                $customers[0]->delete();
            },
        );

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.allowances.family_members.used',
                2,
            )
            ->assertJsonPath(
                'data.plan.allowances.customers.used',
                2,
            );
    }

    public function test_unlimited_has_no_normal_limits_but_still_reports_real_usage(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $user = $this->actAs($business);

        BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'manual',
            'access_starts_at' => now()->subMinute(),
            'access_ends_at' => now()->addDay(),
        ]);

        $period = $this->periodStart($business);

        UserMonthlyUsage::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'period_start' => $period,
            'sender_remittances_used' => 23,
            'sender_remittance_bonus' => 5,
        ]);

        BusinessMonthlyUsage::create([
            'business_id' => $business->id,
            'period_start' => $period,
            'manager_transactions_used' => 31,
            'manager_transaction_bonus' => 20,
            'edits_used' => 27,
        ]);

        $response = $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.tier',
                PlanLimits::UNLIMITED,
            )
            ->assertJsonPath(
                'data.plan.access_source',
                'manual',
            )
            ->assertJsonPath(
                'data.plan.entitlements.monthly_pdf',
                true,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.used',
                23,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.used',
                31,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.used',
                27,
            );

        foreach ([
            'sender_remittances',
            'family_members',
            'manager_transactions',
            'customers',
            'edits',
        ] as $allowance) {
            $this->assertNull(
                $response->json(
                    "data.plan.allowances.{$allowance}.limit"
                )
            );

            $this->assertNull(
                $response->json(
                    "data.plan.allowances.{$allowance}.remaining"
                )
            );
        }

        $this->assertNotNull(
            $response->json('data.plan.access_starts_at')
        );

        $this->assertNotNull(
            $response->json('data.plan.access_ends_at')
        );
    }

    public function test_expired_unlimited_falls_back_to_free_without_deleting_subscription_history(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->actAs($business);

        BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'trial',
            'access_starts_at' => now()->subDays(8),
            'access_ends_at' => now()->subMinute(),
            'trial_used_at' => now()->subDays(8),
        ]);

        $response = $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath(
                'data.plan.tier',
                PlanLimits::FREE,
            )
            ->assertJsonPath(
                'data.plan.access_source',
                'trial',
            )
            ->assertJsonPath(
                'data.plan.entitlements.monthly_pdf',
                false,
            )
            ->assertJsonPath(
                'data.plan.allowances.sender_remittances.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.manager_transactions.limit',
                20,
            )
            ->assertJsonPath(
                'data.plan.allowances.edits.limit',
                20,
            );

        // Expiry changes effective access, not historical metadata.
        $this->assertNotNull(
            $response->json('data.plan.access_starts_at')
        );

        $this->assertNotNull(
            $response->json('data.plan.access_ends_at')
        );

        $this->assertDatabaseHas(
            'business_subscriptions',
            [
                'business_id' => $business->id,
                'tier' => PlanLimits::UNLIMITED,
                'access_source' => 'trial',
            ],
        );
    }

    public function test_monthly_allowances_share_the_same_business_timezone_reset_date(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->actAs($business);

        $clock = BusinessClock::for($business);

        $expected = $clock
            ->toLocal(now())
            ->modify('first day of next month')
            ->format('Y-m-d');

        $response = $this->getJson('/api/v1/me')
            ->assertOk();

        $this->assertSame(
            $expected,
            $response->json(
                'data.plan.allowances.sender_remittances.resets_on'
            ),
        );

        $this->assertSame(
            $expected,
            $response->json(
                'data.plan.allowances.manager_transactions.resets_on'
            ),
        );

        $this->assertSame(
            $expected,
            $response->json(
                'data.plan.allowances.edits.resets_on'
            ),
        );

        $this->assertNull(
            $response->json(
                'data.plan.allowances.family_members.resets_on'
            ),
        );

        $this->assertNull(
            $response->json(
                'data.plan.allowances.customers.resets_on'
            ),
        );
    }

    private function periodStart(Business $business): string
    {
        return app(PlanQuotaService::class)->periodStart(
            $business,
            now(),
        );
    }

    /**
     * @return array<int, SenderRecipient>
     */
    private function seedFamily(
        Business $business,
        User $user,
        int $count,
    ): array {
        return app(CurrentBusiness::class)->runAsUser(
            $business,
            $user->id,
            function () use ($count) {
                $records = [];

                for ($i = 1; $i <= $count; $i++) {
                    $records[] = SenderRecipient::create([
                        'name' => "Family {$i}",
                    ]);
                }

                return $records;
            },
        );
    }

    /**
     * @return array<int, Customer>
     */
    private function seedCustomers(
        Business $business,
        int $count,
    ): array {
        return app(CurrentBusiness::class)->runAs(
            $business,
            function () use ($count) {
                $records = [];

                for ($i = 1; $i <= $count; $i++) {
                    $records[] = Customer::create([
                        'name' => "Customer {$i}",
                    ]);
                }

                return $records;
            },
        );
    }
}