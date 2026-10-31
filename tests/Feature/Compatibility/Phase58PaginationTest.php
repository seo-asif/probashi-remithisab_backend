<?php

namespace Tests\Feature\Compatibility;

use App\Models\SenderSubscription;
use App\Support\Plans\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58PaginationTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_family_defaults_to_ten_newest_first_and_uses_total_for_load_more(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);

        SenderSubscription::create([
            'user_id' => $user->id,
            'tier' => PlanLimits::SENDER_PLUS,
            'access_source' => 'manual',
            'access_starts_at' => now()->subMinute(),
            'access_ends_at' => now()->addDay(),
        ]);

        $ids = [];

        for ($i = 1; $i <= 12; $i++) {
            $ids[] = $this->recipient([
                'name' => 'Member '.chr(64 + $i),
                'phone' => null,
            ])['id'];
        }

        $this->getJson('/api/v1/sender-recipients')
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.items.0.id', $ids[11])
            ->assertJsonPath('data.meta.page', 1)
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.total', 12)
            ->assertJsonPath('data.meta.last_page', 2);

        $this->getJson('/api/v1/sender-recipients?limit=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $ids[1])
            ->assertJsonPath('data.items.1.id', $ids[0])
            ->assertJsonPath('data.meta.total', 12);

        $this->getJson('/api/v1/sender-recipients?limit=2&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.per_page', 1);

        $this->getJson('/api/v1/sender-recipients?sort=name&limit=2')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $ids[0]);
    }

    public function test_customers_are_newest_first_and_support_limit_and_page(): void
    {
        $this->actAs($this->makeBusiness());
        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->postJson('/api/v1/customers', [
                'name' => 'Customer '.chr(64 + $i),
                'phone' => "0171234500{$i}",
            ])->assertCreated()->json('data.id');
        }

        $this->getJson('/api/v1/customers?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $ids[2])
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2);

        $this->getJson('/api/v1/customers?limit=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $ids[0])
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_salary_and_loan_histories_page_ten_at_a_time_with_total(): void
    {
        $this->actAs($this->makeBusiness());
        $this->putJson('/api/v1/work/settings', [
            'hourly_rate' => '5',
            'currency' => 'FJD',
        ])->assertOk();

        $salaries = [];
        $loans = [];
        for ($i = 1; $i <= 3; $i++) {
            $date = sprintf('2026-09-%02d', $i);
            $this->putJson("/api/v1/work/days/{$date}", [
                'kind' => 'work',
                'hours' => '8',
            ])->assertOk();

            $salaries[] = $this->postJson('/api/v1/work/salaries', [
                'from_date' => $date,
                'to_date' => $date,
            ])->assertCreated()->json('data.id');

            $loans[] = $this->postJson('/api/v1/work/loans', [
                'type' => 'take',
                'amount' => '10',
                'entry_date' => $date,
            ])->assertCreated()->json('data.id');
        }

        $this->getJson('/api/v1/work/salaries?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $salaries[2])
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.per_page', 2);

        $this->getJson('/api/v1/work/salaries?limit=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $salaries[0]);

        $this->getJson('/api/v1/work/loans?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $loans[2])
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.loan_left', '30.00');

        $this->getJson('/api/v1/work/loans?limit=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $loans[0]);
    }
}
