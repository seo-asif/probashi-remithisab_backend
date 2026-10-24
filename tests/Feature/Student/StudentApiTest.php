<?php

namespace Tests\Feature\Student;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_student_fee_feature_is_absent_from_schema_and_api(): void
    {
        $this->actAs($this->makeBusiness());

        $this->assertFalse(Schema::hasTable('student_fees'));
        $this->assertFalse(Schema::hasColumn('student_expenses', 'student_fee_id'));

        foreach ([
            ['GET', '/api/v1/student/fees'],
            ['POST', '/api/v1/student/fees'],
            ['GET', '/api/v1/student/fees/unknown'],
            ['PATCH', '/api/v1/student/fees/unknown'],
            ['POST', '/api/v1/student/fees/unknown/pay'],
            ['POST', '/api/v1/student/fees/unknown/unpay'],
            ['DELETE', '/api/v1/student/fees/unknown'],
            ['POST', '/api/v1/student/fees/unknown/restore'],
        ] as [$method, $url]) {
            $this->json($method, $url)->assertNotFound();
        }

        $this->getJson('/api/v1/student/summary')->assertOk()
            ->assertJsonMissingPath('data.pending_fees');
    }

    public function test_student_expense_remains_editable_deletable_and_restorable(): void
    {
        $this->actAs($this->makeBusiness());

        $expense = $this->postJson('/api/v1/student/expenses', [
            'category' => 'education', 'amount' => '75', 'currency' => 'FJD',
        ])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('fee_id', $expense);

        $this->patchJson("/api/v1/student/expenses/{$expense['id']}", [
            'amount' => '85',
        ])->assertOk()->assertJsonPath('data.amount', '85.00');

        $this->deleteJson("/api/v1/student/expenses/{$expense['id']}")
            ->assertOk();

        $this->postJson("/api/v1/student/expenses/{$expense['id']}/restore")
            ->assertOk()->assertJsonPath('data.amount', '85.00');
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

    public function test_money_in_accepts_family_id_and_preserves_legacy_alias(): void
    {
        $this->actAs($this->makeBusiness());
        $family = $this->recipient();
        $saved = $this->postJson('/api/v1/student/money-in', [
            'from_id' => $family['id'], 'amount_bdt' => '1000', 'method' => 'Bank',
        ])->assertCreated()->assertJsonPath('data.from_id', $family['id'])
            ->assertJsonPath('data.from_recipient_id', $family['id'])
            ->assertJsonPath('data.from_name', $family['name'])->json('data');

        $this->patchJson("/api/v1/student/money-in/{$saved['id']}", [
            'from_recipient_id' => $family['id'], 'note' => 'Updated',
        ])->assertOk()->assertJsonPath('data.from_id', $family['id']);
    }

    public function test_money_in_other_relation_and_legacy_tokens_round_trip(): void
    {
        $this->actAs($this->makeBusiness());
        $other = $this->postJson('/api/v1/student/money-in', [
            'from_id' => '__other__', 'from_name' => 'Karim',
            'from_relation' => 'বাবা', 'amount_bdt' => '1000',
        ])->assertCreated()->assertJsonPath('data.from_name', 'Karim (বাবা)')
            ->assertJsonPath('data.from_id', '__other__')->json('data');

        $this->patchJson("/api/v1/student/money-in/{$other['id']}", [
            'note' => 'Edited',
        ])->assertOk()->assertJsonPath('data.from_name', 'Karim (বাবা)')
            ->assertJsonPath('data.from_id', '__other__');

        $this->postJson('/api/v1/student/money-in', [
            'from_id' => '__other__', 'from_relation' => 'মা',
            'amount_bdt' => '100',
        ])->assertCreated()->assertJsonPath('data.from_name', 'মা');

        foreach (['__fam__', '__father__', '__mother__', '__sib__'] as $token) {
            $this->postJson('/api/v1/student/money-in', [
                'from_id' => $token, 'amount_bdt' => '100',
            ])->assertCreated()->assertJsonPath('data.from_id', $token);
        }
    }

    public function test_money_in_rejects_invalid_family_and_conflicting_ids(): void
    {
        $this->actAs($this->makeBusiness());
        $family = $this->recipient();

        $this->postJson('/api/v1/student/money-in', [
            'from_id' => $family['id'], 'from_recipient_id' => '__other__',
            'amount_bdt' => '100',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_id.0', 'from_id_conflict');

        $this->postJson('/api/v1/student/money-in', [
            'from_id' => $this->uuid(), 'amount_bdt' => '100',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_id.0', 'recipient_not_found');

        $this->postJson('/api/v1/student/money-in', [
            'from_id' => '__other__', 'from_name' => 'Karim',
            'from_relation' => 'wrong', 'amount_bdt' => '100',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_relation.0', 'relation_invalid');

        $this->postJson("/api/v1/sender-recipients/{$family['id']}/archive")->assertOk();
        $this->postJson('/api/v1/student/money-in', [
            'from_id' => $family['id'], 'amount_bdt' => '100',
        ])->assertStatus(422)->assertJsonPath('error_codes.from_id.0', 'recipient_not_found');
    }

}
