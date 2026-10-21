<?php

namespace Tests\Feature\Plans;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Customer;
use App\Models\SenderRecipient;
use App\Models\User;
use App\Support\Plans\PlanLimits;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class ActiveRecordLimitTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_family_limit_blocks_twenty_first_and_delete_frees_a_slot_but_restore_rechecks_limit(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);

        $family = $this->seedFamily($business, $user, 20);

        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Extra Family',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_FAMILY_LIMIT')
            ->assertJsonPath('data.limit', 20)
            ->assertJsonPath('data.used', 20)
            ->assertJsonPath('data.remaining', 0);

        $deleted = $family[0];

        $this->deleteJson(
            "/api/v1/sender-recipients/{$deleted->uuid}"
        )->assertOk();

        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'New Family',
        ])->assertCreated();

        $this->postJson(
            "/api/v1/sender-recipients/{$deleted->uuid}/restore"
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_FAMILY_LIMIT')
            ->assertJsonPath('data.limit', 20)
            ->assertJsonPath('data.used', 20);

        $this->getJson('/api/v1/sender-recipients')
            ->assertJsonPath('data.meta.total', 20);
    }

    public function test_family_limit_is_per_user_not_per_business(): void
    {
        $business = $this->makeBusiness();

        $first = $this->makeMember(
            $business,
            BusinessRole::Operator
        );

        $second = $this->makeMember(
            $business,
            BusinessRole::Operator
        );

        $this->seedFamily($business, $first, 20);

        $this->login($first);

        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Blocked Family',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_FAMILY_LIMIT');

        $this->login($second);

        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Allowed Family',
        ])->assertCreated();

        $this->getJson('/api/v1/sender-recipients')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_customer_limit_blocks_eleventh_and_delete_frees_a_slot_but_restore_rechecks_limit(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        $customers = $this->seedCustomers($business, 10);

        $this->postJson('/api/v1/customers', [
            'name' => 'Extra Customer',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_CUSTOMER_LIMIT')
            ->assertJsonPath('data.limit', 10)
            ->assertJsonPath('data.used', 10)
            ->assertJsonPath('data.remaining', 0);

        $deleted = $customers[0];

        $this->deleteJson(
            "/api/v1/customers/{$deleted->uuid}"
        )->assertOk();

        $this->postJson('/api/v1/customers', [
            'name' => 'New Customer',
        ])->assertCreated();

        $this->postJson(
            "/api/v1/customers/{$deleted->uuid}/restore"
        )
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_CUSTOMER_LIMIT')
            ->assertJsonPath('data.limit', 10)
            ->assertJsonPath('data.used', 10);

        $this->getJson('/api/v1/customers')
            ->assertJsonPath('data.meta.total', 10);
    }

    public function test_customer_limit_is_per_business(): void
    {
        $businessA = $this->makeBusiness();
        $businessB = $this->makeBusiness();

        $userA = $this->makeMember(
            $businessA,
            BusinessRole::Owner
        );

        $userB = $this->makeMember(
            $businessB,
            BusinessRole::Owner
        );

        $this->seedCustomers($businessA, 10);

        $this->login($userA);

        $this->postJson('/api/v1/customers', [
            'name' => 'Blocked Customer',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_CUSTOMER_LIMIT');

        $this->login($userB);

        $this->postJson('/api/v1/customers', [
            'name' => 'Allowed Customer',
        ])->assertCreated();

        $this->getJson('/api/v1/customers')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_unlimited_bypasses_family_and_customer_active_limits(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);

        BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'manual',
            'access_starts_at' => now()->subMinute(),
            'access_ends_at' => now()->addDay(),
        ]);

        $this->seedFamily($business, $user, 20);
        $this->seedCustomers($business, 10);

        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Family Twenty One',
        ])->assertCreated();

        $this->postJson('/api/v1/customers', [
            'name' => 'Customer Eleven',
        ])->assertCreated();

        $this->getJson('/api/v1/sender-recipients')
            ->assertJsonPath('data.meta.total', 21);

        $this->getJson('/api/v1/customers')
            ->assertJsonPath('data.meta.total', 11);
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