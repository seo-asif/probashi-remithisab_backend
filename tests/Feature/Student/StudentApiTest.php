<?php

namespace Tests\Feature\Student;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use App\Models\StudentExpense;
use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class StudentApiTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_student_setting_and_opening_balance_are_user_private(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);

        $this->putJson('/api/v1/student/settings', ['enabled' => true])
            ->assertOk()->assertJsonPath('data.enabled', true);

        $this->putJson('/api/v1/student/opening-balances', [
            'currency' => 'FJD', 'amount' => '125.50',
        ])->assertOk()->assertJsonPath('data.amount', '125.50');

        $second = $this->makeMember($business, BusinessRole::Operator);
        $this->login($second);

        $this->getJson('/api/v1/student/settings')
            ->assertOk()->assertJsonPath('data.enabled', false);

        $this->getJson('/api/v1/student/opening-balances')
            ->assertOk()->assertJsonCount(0, 'data.items');

        $this->login($first);
        $this->getJson('/api/v1/student/opening-balances')
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_expense_and_received_money_remain_in_separate_currencies(): void
    {
        $this->actAs($this->makeBusiness());

        $this->putJson('/api/v1/student/opening-balances', [
            'currency' => 'FJD', 'amount' => '100',
        ])->assertOk();

        $this->postJson('/api/v1/student/money-in', [
            'from_name' => 'Family',
            'amount_bdt' => '10000',
            'rate_bdt_per_currency' => '50',
            'estimate_currency' => 'FJD',
            'method' => 'Bank',
        ])->assertCreated()
            ->assertJsonPath('data.estimated_foreign_amount', '200.00');

        $this->postJson('/api/v1/student/expenses', [
            'category' => 'food', 'amount' => '40',
            'currency' => 'FJD',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/student/summary')
            ->assertOk();

        $rows = collect($response->json('data.by_currency'))->keyBy('currency');
        $this->assertSame('10000.00', $rows['BDT']['period_received']);
        $this->assertSame('10000.00', $rows['BDT']['remaining_as_of_to']);
        $this->assertSame('40.00', $rows['FJD']['period_spent']);
        $this->assertSame('60.00', $rows['FJD']['remaining_as_of_to']);
    }

    public function test_fee_is_counted_only_when_paid_and_never_twice(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);

        $fee = $this->postJson('/api/v1/student/fees', [
            'name' => 'Tuition', 'amount' => '300',
            'currency' => 'FJD',
            'due_on' => now()->addDays(15)->format('Y-m-d'),
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/student/summary')
            ->assertOk()->assertJsonPath('data.pending_fees', 1);

        $this->postJson("/api/v1/student/fees/{$fee['id']}/pay", [])
            ->assertOk()->assertJsonPath('data.paid', true);
        $this->postJson("/api/v1/student/fees/{$fee['id']}/pay", [])
            ->assertOk();

        $this->assertSame(1, $this->inStudentContext($business, $user, fn () => StudentExpense::query()->count()));
        $summary = $this->getJson('/api/v1/student/summary')->assertOk()->json('data');
        $spent = collect($summary['by_currency'])->firstWhere('currency', 'FJD');
        $this->assertSame('300.00', $spent['period_spent']);
        $this->assertSame(0, $summary['pending_fees']);

        $this->postJson("/api/v1/student/fees/{$fee['id']}/unpay")
            ->assertOk()->assertJsonPath('data.paid', false);
        $this->assertSame(0, $this->inStudentContext($business, $user, fn () => StudentExpense::query()->count()));

        $this->postJson("/api/v1/student/fees/{$fee['id']}/pay", [])
            ->assertOk();
        $this->assertSame(1, $this->inStudentContext($business, $user, fn () => StudentExpense::query()->count()));
        $this->assertSame(1, $this->inStudentContext($business, $user, fn () => StudentExpense::withTrashed()->count()));
    }

    public function test_manual_expense_cannot_modify_fee_linked_expense(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $fee = $this->postJson('/api/v1/student/fees', [
            'name' => 'Registration', 'amount' => '75',
            'currency' => 'FJD', 'due_on' => now()->format('Y-m-d'),
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/student/fees/{$fee['id']}/pay")->assertOk();
        $expense = $this->inStudentContext($business, $user, fn () => StudentExpense::query()->firstOrFail());
        $this->patchJson("/api/v1/student/expenses/{$expense->uuid}", [
            'amount' => '1',
        ])->assertStatus(409)->assertJsonPath('code', 'STUDENT_FEE_LINKED');

        $this->deleteJson("/api/v1/student/expenses/{$expense->uuid}")
            ->assertStatus(409)->assertJsonPath('code', 'STUDENT_FEE_LINKED');
    }

    public function test_duplicate_expense_uuid_does_not_create_a_second_expense(): void
    {
        $this->actAs($this->makeBusiness());
        $uuid = $this->uuid();

        $payload = [
            'id' => $uuid,
            'category' => 'food',
            'amount' => '12.50',
            'currency' => 'FJD',
        ];

        $this->postJson('/api/v1/student/expenses', $payload)->assertCreated();
        $this->postJson('/api/v1/student/expenses', [
            ...$payload,
            'amount' => '99',
        ])->assertOk()->assertJsonPath('data.amount', '12.50');

        $this->assertDatabaseCount('student_expenses', 1);
    }

    public function test_invalid_money_and_cross_user_record_access_are_rejected(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);

        $this->postJson('/api/v1/student/expenses', [
            'category' => 'food', 'amount' => '1.999',
        ])->assertStatus(422);

        $mine = $this->postJson('/api/v1/student/expenses', [
            'category' => 'food', 'amount' => '5',
        ])->assertCreated()->json('data');

        $second = $this->makeMember($business, BusinessRole::Operator);
        $this->login($second);
        $this->getJson("/api/v1/student/expenses/{$mine['id']}")->assertNotFound();
        $this->patchJson("/api/v1/student/expenses/{$mine['id']}", [
            'amount' => '6',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/student/expenses/{$mine['id']}")
            ->assertNotFound();

        $this->login($first);
        $this->getJson("/api/v1/student/expenses/{$mine['id']}")
            ->assertOk()->assertJsonPath('data.amount', '5.00');
    }

    public function test_delete_and_restore_fee_preserves_one_linked_expense(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);

        $fee = $this->postJson('/api/v1/student/fees', [
            'name' => 'Tuition',
            'amount' => '200',
            'currency' => 'FJD',
            'due_on' => now()->format('Y-m-d'),
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/student/fees/{$fee['id']}/pay")->assertOk();
        $this->deleteJson("/api/v1/student/fees/{$fee['id']}")
            ->assertOk();
        $this->assertDatabaseCount('student_expenses', 1);
        $this->assertSame(0, $this->inStudentContext($business, $user, fn () => StudentExpense::query()->count()));

        $this->postJson("/api/v1/student/fees/{$fee['id']}/restore")
            ->assertOk()->assertJsonPath('data.paid', true);

        $this->assertSame(1, $this->inStudentContext($business, $user, fn () => StudentExpense::query()->count()));
        $this->assertSame(1, $this->inStudentContext($business, $user, fn () => StudentFee::query()->count()));
    }

    /**
     * Assertions must set the same owner context as the API middleware.
     * Without an active owner, tenant and owner scopes fail closed.
     */
    private function inStudentContext(Business $business, User $user, callable $callback): mixed
    {
        return app(CurrentBusiness::class)->runAsUser($business, $user->id, $callback);
    }
}
