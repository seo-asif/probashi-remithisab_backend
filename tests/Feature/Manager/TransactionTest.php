<?php

namespace Tests\Feature\Manager;

use App\Models\BdtPayment;
use App\Models\ManagerTransaction;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness(['timezone' => 'Pacific/Fiji']));
        $this->customer = $this->customer();
    }

    public function test_m1_m2_obligation_and_customer_total_are_computed_by_the_server(): void
    {
        $tx = $this->transaction($this->customer['id'], ['commission' => '5', 'obligation_bdt' => '26000.00']);

        $this->assertSame('26000.00', $tx['obligation_bdt']);
        $this->assertSame('505.00', $tx['customer_pays']);
        $this->assertSame('0.00', $tx['paid_bdt']);
        $this->assertSame('26000.00', $tx['owed_bdt']);
        $this->assertSame('currency_received', $tx['status']);
        $this->assertSame([], $tx['payments']);
    }

    public function test_m3_to_m6_partial_payments_add_up_and_the_last_one_marks_it_sent(): void
    {
        $tx = $this->transaction($this->customer['id']);

        // M3
        $this->pay($tx['id'], '13000')->assertCreated()
            ->assertJsonPath('data.transaction.paid_bdt', '13000.00')
            ->assertJsonPath('data.transaction.owed_bdt', '13000.00')
            ->assertJsonPath('data.transaction.status', 'currency_received');
        // M4
        $this->pay($tx['id'], '5000')->assertCreated()
            ->assertJsonPath('data.transaction.paid_bdt', '18000.00')
            ->assertJsonPath('data.transaction.owed_bdt', '8000.00');
        // M6: more than what is still owed
        $this->pay($tx['id'], '9000')->assertStatus(422)
            ->assertJsonPath('error_codes.amount_bdt.0', 'payment_too_much')
            ->assertJsonPath('errors.amount_bdt.0', 'This is more than the 8000.00 BDT still to pay.');
        // M5
        $this->pay($tx['id'], '8000')->assertCreated()
            ->assertJsonPath('data.transaction.paid_bdt', '26000.00')
            ->assertJsonPath('data.transaction.status', 'bdt_sent');

        $this->getJson("/api/v1/manager-transactions/{$tx['id']}")->assertJsonCount(3, 'data.payments');
        $this->assertSame(3, $this->audits('manager_transaction.payment_added'));
    }

    public function test_m7_pending_has_no_payments_and_no_commission(): void
    {
        $tx = $this->transaction($this->customer['id'], ['status' => 'currency_pending', 'commission' => '5']);

        $this->pay($tx['id'], '100')->assertStatus(422)->assertJsonPath('error_codes.amount_bdt.0', 'customer_not_paid');
        $this->assertSame('0.00', $tx['paid_bdt']);
    }

    public function test_m8_an_edit_below_what_is_paid_is_refused_and_equal_marks_it_sent(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $this->pay($tx['id'], '13000');
        $this->pay($tx['id'], '5000');

        // rate 30 -> obligation 15,000 < 18,000 paid
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '30'])
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'below_paid')
            ->assertJsonPath('errors.amount.0', '18000.00 BDT has already been paid. The total cannot be less than that.');

        // rate 36 -> obligation 18,000 = paid -> bdt_sent
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '36'])
            ->assertOk()->assertJsonPath('data.status', 'bdt_sent')->assertJsonPath('data.owed_bdt', '0.00');

        // rate back up -> money no longer covers it -> currency_received, payments kept
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '52'])
            ->assertOk()->assertJsonPath('data.status', 'currency_received')->assertJsonPath('data.paid_bdt', '18000.00');
    }

    public function test_m9_m10_marking_sent_by_hand_and_moving_back(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $this->pay($tx['id'], '13000');

        // M9: one automatic payment for the rest
        $sent = $this->patchJson("/api/v1/manager-transactions/{$tx['id']}/status", ['status' => 'bdt_sent'])->assertOk()->json('data');
        $this->assertSame('26000.00', $sent['paid_bdt']);
        $this->assertCount(2, $sent['payments']);
        $this->assertTrue($sent['payments'][1]['auto']);
        $this->assertSame('13000.00', $sent['payments'][1]['amount_bdt']);

        // M10: back to received removes every payment (kept as deleted rows + audit)
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}/status", ['status' => 'currency_received'])
            ->assertOk()->assertJsonPath('data.paid_bdt', '0.00')->assertJsonPath('data.payments', []);
        $this->assertSame(2, BdtPayment::withoutGlobalScopes()->onlyTrashed()->count());
        $this->assertSame(1, $this->audits('manager_transaction.payments_removed'));
    }

    public function test_created_as_sent_gets_one_automatic_payment_and_pending_removes_payments(): void
    {
        $tx = $this->transaction($this->customer['id'], ['status' => 'BDT Sent']); // display label accepted
        $this->assertSame('bdt_sent', $tx['status']);
        $this->assertSame('26000.00', $tx['paid_bdt']);
        $this->assertTrue($tx['payments'][0]['auto']);
        $this->assertSame('2026-09-15', $tx['payments'][0]['paid_on']);

        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}/status", ['status' => 'currency_pending'])
            ->assertOk()->assertJsonPath('data.paid_bdt', '0.00')->assertJsonPath('data.status', 'currency_pending');
    }

    public function test_a_wrong_payment_is_corrected_by_a_reversal_entry(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $p = $this->pay($tx['id'], '26000')->json('data.payment');

        $r = $this->postJson("/api/v1/manager-transactions/{$tx['id']}/payments/{$p['id']}/reverse", ['note' => 'Typed wrong'])->assertCreated()->json('data');
        $this->assertSame('-26000.00', $r['payment']['amount_bdt']);
        $this->assertSame($p['id'], $r['payment']['reverses_id']);
        $this->assertSame('0.00', $r['transaction']['paid_bdt']);
        $this->assertSame('currency_received', $r['transaction']['status']);
        $this->assertCount(2, $r['transaction']['payments']); // the original stays, never edited

        $this->postJson("/api/v1/manager-transactions/{$tx['id']}/payments/{$p['id']}/reverse")->assertStatus(422)->assertJsonPath('error_codes.payment.0', 'already_reversed');
        $this->postJson("/api/v1/manager-transactions/{$tx['id']}/payments/{$r['payment']['id']}/reverse")->assertStatus(422)->assertJsonPath('error_codes.payment.0', 'cannot_reverse_reversal');
    }

    public function test_d1_the_phone_id_is_kept_and_a_repeat_saves_nothing_new(): void
    {
        $id = $this->uuid();

        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['id' => $id]))->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['id' => $id, 'amount' => '1']))
            ->assertOk()->assertJsonPath('message', 'Already saved.')->assertJsonPath('data.amount', '500.00');
        $this->assertSame(1, ManagerTransaction::withoutGlobalScopes()->count());

        $pid = $this->uuid();
        $tx = ManagerTransaction::withoutGlobalScopes()->first()->uuid;
        $this->pay($tx, '100', ['id' => $pid])->assertCreated();
        $this->pay($tx, '100', ['id' => $pid])->assertOk();
        $this->getJson("/api/v1/manager-transactions/{$tx}")->assertJsonPath('data.paid_bdt', '100.00');
    }

    public function test_an_id_used_by_another_business_is_refused_without_saying_whose(): void
    {
        $id = $this->uuid();
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['id' => $id]))->assertCreated();

        $this->actAs($this->makeBusiness());
        $other = $this->customer();
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($other['id'], ['id' => $id]))
            ->assertStatus(422)->assertJsonPath('error_codes.id.0', 'id_taken');
        $this->getJson("/api/v1/manager-transactions/{$id}")->assertStatus(404);
    }

    public function test_every_rejected_field_has_a_code(): void
    {
        $res = $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], [
            'amount'      => '10000.01', 'rate'                               => '50.255', 'commission' => '5000', 'receive_method' => 'Bitcoin',
            'occurred_at' => now()->addDay()->format('Y-m-d H:i'), 'currency' => 'XYZ',
        ]))->assertStatus(422);

        $this->assertSame([
            'receive_method' => ['receive_method_invalid'], 'currency' => ['currency_invalid'], 'amount' => ['amount_too_large'],
            'rate'           => ['rate_invalid'], 'occurred_at'        => ['date_in_future'],
        ], array_intersect_key($res->json('error_codes'), array_flip(['receive_method', 'currency', 'amount', 'rate', 'occurred_at'])));
        $this->assertSame('Amount is too large (maximum 10,000).', $res->json('errors.amount.0'));
    }

    public function test_commission_withdrawal_and_mismatch_rules(): void
    {
        // commission at most 20% of 500 = 100; withdrawal at most 20% of base 26,000 = 5,200
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['commission' => '100.01', 'withdrawal_charge' => '5200.01']))
            ->assertStatus(422)
            ->assertJsonPath('error_codes.commission.0', 'commission_too_high')
            ->assertJsonPath('error_codes.withdrawal_charge.0', 'withdrawal_too_high');

        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['obligation_bdt' => '25999.99']))
            ->assertStatus(422)->assertJsonPath('error_codes.obligation_bdt.0', 'calc_mismatch');

        $tx = $this->transaction($this->customer['id'], ['commission' => '100', 'withdrawal_charge' => '5200']);
        $this->assertSame('31200.00', $tx['obligation_bdt']);
    }

    public function test_time_is_stored_in_utc_with_the_timezone_it_was_entered_in(): void
    {
        $fiji = $this->transaction($this->customer['id'], ['occurred_at' => '2026-09-15 10:00']);
        $this->assertSame('2026-09-14T22:00:00Z', $fiji['occurred_at']);
        $this->assertSame('Pacific/Fiji', $fiji['timezone']);

        $dhaka = $this->transaction($this->customer['id'], ['occurred_at' => '2026-09-15 10:00', 'timezone' => 'Asia/Dhaka']);
        $this->assertSame('2026-09-15T04:00:00Z', $dhaka['occurred_at']);
        $this->assertSame('Asia/Dhaka', $dhaka['timezone']);

        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['timezone' => 'Mars/Base']))
            ->assertStatus(422)->assertJsonPath('error_codes.timezone.0', 'timezone_invalid');
    }

    public function test_pay_targets_and_other_payment_method(): void
    {
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($this->customer['id'], ['pay_to' => '01799999999']))
            ->assertStatus(422)->assertJsonPath('error_codes.pay_to.0', 'pay_to_not_saved');

        $bank = $this->transaction($this->customer['id'], ['receive_method' => 'bank', 'pay_to' => '1234-567890', 'withdrawal_charge' => '50']);
        $this->assertSame('Sonali Bank · Karim Uddin · 1234567890', $bank['pay_to']);
        $this->assertSame('0.00', $bank['withdrawal_charge']);

        $other = $this->transaction($this->customer['id'], ['receive_method' => 'other payment method', 'pay_to' => null]);
        $this->assertSame('Other Payment Method', $other['receive_method']);
        $this->assertNull($other['pay_to']);
    }

    public function test_wallet_destination_hash_is_stored_from_the_resolved_destination(): void
    {
        $tx = $this->transaction($this->customer['id']);

        $stored = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $tx['id'])
            ->firstOrFail();

        $expected = app(IdentifierHasher::class)->hash(
            'wallet_bkash',
            IdentifierNormalizer::wallet($tx['pay_to'])
        );

        $this->assertNotNull($stored->destination_hash);
        $this->assertSame(64, strlen($stored->destination_hash));
        $this->assertSame($expected, $stored->destination_hash);
    }

    public function test_bank_destination_hash_uses_account_number_not_display_label(): void
    {
        $tx = $this->transaction($this->customer['id'], [
            'receive_method' => 'bank',
            'pay_to'         => '1234-567890',
        ]);

        $stored = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $tx['id'])
            ->firstOrFail();

        $expected = app(IdentifierHasher::class)->hash(
            'bank_account',
            IdentifierNormalizer::bankAccount('1234567890')
        );

        $this->assertSame(
            'Sonali Bank · Karim Uddin · 1234567890',
            $tx['pay_to']
        );

        $this->assertSame(
            '1234567890',
            $stored->bank['account_number']
        );

        $this->assertSame(
            $expected,
            $stored->destination_hash
        );
    }

    public function test_unrelated_transaction_update_preserves_destination_hash(): void
    {
        $tx = $this->transaction($this->customer['id']);

        $stored = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $tx['id'])
            ->firstOrFail();

        $originalHash = $stored->destination_hash;

        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}",
            ['rate' => '53']
        )->assertOk();

        $stored->refresh();

        $this->assertNotNull($originalHash);
        $this->assertSame(
            $originalHash,
            $stored->destination_hash
        );
    }

    public function test_payment_method_without_destination_has_null_destination_hash(): void
    {
        $tx = $this->transaction($this->customer['id'], [
            'receive_method' => 'other payment method',
            'pay_to'         => null,
        ]);

        $stored = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $tx['id'])
            ->firstOrFail();

        $this->assertSame(
            'Other Payment Method',
            $tx['receive_method']
        );

        $this->assertNull($tx['pay_to']);
        $this->assertNull($stored->destination_hash);
    }

    public function test_changing_destination_changes_destination_hash(): void
    {
        $tx = $this->transaction($this->customer['id']);

        $stored = ManagerTransaction::withoutGlobalScopes()
            ->where('uuid', $tx['id'])
            ->firstOrFail();

        $originalHash = $stored->destination_hash;

        $this->patchJson(
            "/api/v1/manager-transactions/{$tx['id']}",
            [
                'receive_method' => 'bank',
                'pay_to'         => '1234-567890',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.pay_to',
                'Sonali Bank · Karim Uddin · 1234567890'
            );

        $stored->refresh();

        $expected = app(IdentifierHasher::class)->hash(
            'bank_account',
            IdentifierNormalizer::bankAccount('1234567890')
        );

        $this->assertNotSame(
            $originalHash,
            $stored->destination_hash
        );

        $this->assertSame(
            $expected,
            $stored->destination_hash
        );
    }

    public function test_every_money_change_is_in_the_audit_log(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '53'])->assertOk();
        $this->patchJson("/api/v1/manager-transactions/{$tx['id']}", ['rate' => '53'])->assertOk(); // no real change: no row
        $this->deleteJson("/api/v1/manager-transactions/{$tx['id']}")->assertOk();
        $this->postJson("/api/v1/manager-transactions/{$tx['id']}/restore")->assertOk()->assertJsonPath('data.id', $tx['id']);

        $this->assertSame(1, $this->audits('manager_transaction.created'));
        $this->assertSame(1, $this->audits('manager_transaction.updated'));
        $this->assertSame(1, $this->audits('manager_transaction.deleted'));
        $this->assertSame(1, $this->audits('manager_transaction.restored'));

        $row = \App\Models\AuditLog::query()->where('action', 'manager_transaction.updated')->first();
        $this->assertSame(['rate' => '52.00', 'base_bdt' => '26000.00', 'obligation_bdt' => '26000.00'], $row->metadata['old']);
        $this->assertSame('26500.00', $row->metadata['new']['obligation_bdt']);
    }

    public function test_list_filters_including_still_owed(): void
    {
        $a = $this->transaction($this->customer['id']);
        $this->transaction($this->customer['id'], ['status' => 'bdt_sent', 'occurred_at' => '2026-08-31 23:30']);
        $this->transaction($this->customer['id'], ['status' => 'currency_pending']);
        $this->pay($a['id'], '1000');

        $this->getJson('/api/v1/manager-transactions?owed=1')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.id', $a['id']);
        $this->getJson('/api/v1/manager-transactions?status=currency_pending')->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/manager-transactions?from=2026-09-01&to=2026-09-30')->assertJsonPath('data.meta.total', 2);
    }

    public function test_search_finds_names_and_exact_sensitive_identifiers(): void
    {
        $walletTx = $this->transaction($this->customer['id']);

        // Non-sensitive snapshot/customer names remain partially searchable.
     $this->getJson('/api/v1/manager-transactions?q=rah')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $walletTx['id']);

       $this->getJson(
    '/api/v1/manager-transactions?q=' . urlencode($this->customer['phone'])
)
    ->assertJsonPath('data.meta.total', 1)
    ->assertJsonPath('data.items.0.id', $walletTx['id']);

        // Historical wallet destination is exact-match through destination_hash.
        $this->getJson('/api/v1/manager-transactions?q=' . urlencode($walletTx['pay_to']))
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $walletTx['id']);

        // Partial sensitive identifiers must not match.
        $this->getJson('/api/v1/manager-transactions?q=01712345')
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/v1/manager-transactions?q=99999')
            ->assertJsonPath('data.meta.total', 0);
            // Bank destination uses exact normalized account-number HMAC lookup.
$bankTx = $this->transaction($this->customer['id'], [
    'receive_method' => 'bank',
    'pay_to' => '1234-567890',
]);

$this->getJson('/api/v1/manager-transactions?q=1234567890')
    ->assertJsonPath('data.meta.total', 1)
    ->assertJsonPath('data.items.0.id', $bankTx['id']);

// Equivalent formatted account number normalizes to the same hash.
$this->getJson('/api/v1/manager-transactions?q=1234-567890')
    ->assertJsonPath('data.meta.total', 1)
    ->assertJsonPath('data.items.0.id', $bankTx['id']);

// Partial bank-account identifiers must not match.
$this->getJson('/api/v1/manager-transactions?q=456789')
    ->assertJsonPath('data.meta.total', 0);
    }

    public function test_a_transaction_of_a_deleted_customer_is_restored_only_after_the_customer(): void
    {
        $tx = $this->transaction($this->customer['id']);
        $this->deleteJson("/api/v1/manager-transactions/{$tx['id']}")->assertOk();
        $this->deleteJson("/api/v1/customers/{$this->customer['id']}")->assertOk();

        $this->postJson("/api/v1/manager-transactions/{$tx['id']}/restore")->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_DELETED');
        $this->postJson("/api/v1/customers/{$this->customer['id']}/restore")->assertOk();
        $this->postJson("/api/v1/manager-transactions/{$tx['id']}/restore")->assertOk();
    }
}
