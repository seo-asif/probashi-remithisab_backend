<?php

namespace Tests\Feature\Sender;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\SenderRemittance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class SenderModeTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->owner = $this->makeMember($this->business, BusinessRole::Owner);
        $this->worker = $this->makeMember($this->business, BusinessRole::Operator);
        $this->login($this->worker);
    }

    public function test_recipients_validate_names_and_have_no_payment_fields(): void
    {
        $r = $this->recipient([
            'name'          => ' আম্মা ',
            'phone'         => '+880 1811-111111',
            'bkash_numbers' => ['01711111111'],
        ]);

        $this->assertSame('আম্মা', $r['name']);
        $this->assertSame('+8801811111111', $r['phone']);
        $this->assertArrayNotHasKey('bkash_numbers', $r);
        $this->postJson('/api/v1/sender-recipients', [
            'name' => 'A',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_codes.name.0', 'name_length');

        $this->postJson('/api/v1/sender-recipients', ['name' => 'Bhai', 'phone' => '01811111111'])
            ->assertStatus(422)->assertJsonPath('error_codes.phone.0', 'phone_duplicate');
    }

    public function test_s1_s2_through_the_api_with_myself_and_a_family_member(): void
    {
        $s1 = $this->senderRemittance(['family_bdt' => '25625.00']); // the app's own result matches
        $this->assertSame('Myself', $s1['recipient']['name']);
        $this->assertSame('25625.00', $s1['family_bdt']);
        $this->assertSame('625.00', $s1['incentive_bdt']);
        $this->assertSame('510.00', $s1['total_cost']);
        $this->assertFalse($s1['received']);
        $this->assertSame('2026-09-20T06:30:00Z', $s1['occurred_at']);
        $this->assertSame('Pacific/Fiji', $s1['timezone']);

        $mother = $this->recipient();
        $s2 = $this->senderRemittance(['recipient_id' => $mother['id'], 'recipient_type' => 'family', 'charge_type' => 'included']);
        $this->assertSame('আম্মা', $s2['recipient']['name']);
        $this->assertSame('490.00', $s2['principal']);
        $this->assertSame('25112.50', $s2['family_bdt']);
        $this->assertSame('500.00', $s2['total_cost']);
    }

    public function test_sender_refusals_have_codes(): void
    {

        $this->senderRemittance([
            'amount'      => '100.00',
            'charge'      => '10000.00',
            'charge_type' => 'excluded',
        ]);

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'amount'      => '100.00',
            'charge'      => '100000.01',
            'charge_type' => 'excluded',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_codes.charge.0', 'charge_too_large');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['charge' => '500', 'charge_type' => 'included']))
            ->assertStatus(422)->assertJsonPath('error_codes.charge.0', 'charge_not_less_than_amount'); // S6
        $this->senderRemittance(['amount' => '100', 'charge' => '150', 'charge_type' => 'excluded']); // S7 accepted
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['incentive_pct' => '12']))
            ->assertStatus(422)->assertJsonPath('error_codes.incentive_pct.0', 'incentive_too_high')
            ->assertJsonPath('errors.incentive_pct.0', 'Incentive cannot be more than 10%.'); // S8
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['rate' => '50.255']))
            ->assertStatus(422)->assertJsonPath('error_codes.rate.0', 'rate_invalid'); // S9
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['amount' => '0']))
            ->assertStatus(422)->assertJsonPath('error_codes.amount.0', 'amount_invalid'); // S10
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['family_bdt' => '25625.01']))
            ->assertStatus(422)->assertJsonPath('error_codes.family_bdt.0', 'calc_mismatch');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['method' => 'Cash', 'purpose' => 'party']))
            ->assertStatus(422)->assertJsonPath('error_codes.method.0', 'method_invalid')->assertJsonPath('error_codes.purpose.0', 'purpose_invalid');
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'amount' => '10000.01',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_codes.amount.0', 'amount_too_large');

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'rate' => '400.01',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error_codes.rate.0', 'rate_too_large');
    }

    public function test_family_got_it_is_confirmed_and_can_be_undone(): void
    {
        $r = $this->senderRemittance();

        $this->postJson("/api/v1/sender-remittances/{$r['id']}/received", ['received' => true])
            ->assertOk()->assertJsonPath('data.received', true);
        $this->assertNotNull(SenderRemittance::withoutGlobalScopes()->first()->received_at);

        $this->getJson('/api/v1/sender-remittances?received=0')->assertJsonPath('data.meta.total', 0);

        $this->postJson("/api/v1/sender-remittances/{$r['id']}/received", ['received' => false])
            ->assertOk()->assertJsonPath('data.received', false)->assertJsonPath('data.received_at', null);
        $this->assertSame(1, $this->audits('sender_remittance.received'));
    }

    public function test_edits_are_checked_with_the_stored_values_and_logged(): void
    {
        $r = $this->senderRemittance();

        $this->patchJson("/api/v1/sender-remittances/{$r['id']}", ['charge_type' => 'included', 'charge' => '500'])
            ->assertStatus(422)->assertJsonPath('error_codes.charge.0', 'charge_not_less_than_amount');
        $this->patchJson("/api/v1/sender-remittances/{$r['id']}", ['rate' => '51'])
            ->assertOk()->assertJsonPath('data.family_bdt', '26137.50');

        $mother = $this->recipient();
        $this->patchJson("/api/v1/sender-remittances/{$r['id']}", ['recipient_id' => $mother['id']])
            ->assertOk()->assertJsonPath('data.recipient_type', 'family')->assertJsonPath('data.recipient.name', 'আম্মা');

        $this->assertSame(2, $this->audits('sender_remittance.updated'));
    }

    public function test_savings_goals_count_linked_money(): void
    {
        $old = $this->senderRemittance(); // Myself, 25,625 BDT, not in a goal
        $goal = $this->postJson('/api/v1/savings-goals', ['name' => 'Build a house', 'target_bdt' => '50000', 'include_past_self' => true])
            ->assertCreated()->assertJsonPath('data.saved_bdt', '25625.00')->assertJsonPath('data.left_bdt', '24375.00')->json('data');

        $this->senderRemittance(['goal_id' => $goal['id'], 'purpose' => 'house']);
        $this->getJson('/api/v1/savings-goals')->assertJsonPath('data.items.0.reached', true)->assertJsonPath('data.items.0.left_bdt', '0.00');

        $this->postJson('/api/v1/savings-goals', ['name' => '', 'target_bdt' => '0'])
            ->assertStatus(422)->assertJsonPath('error_codes.name.0', 'goal_name_required')->assertJsonPath('error_codes.target_bdt.0', 'target_bdt_invalid');

        $this->deleteJson("/api/v1/savings-goals/{$goal['id']}")->assertOk();
        $this->getJson("/api/v1/sender-remittances/{$old['id']}")->assertJsonPath('data.goal', null);
    }

    public function test_history_and_single_record_include_business_local_datetime(): void
    {
        // Fiji 1 Oct 00:15 is still 30 Sep in UTC. The client must not use
        // the phone's timezone when displaying or editing this remittance.
        $record = $this->senderRemittance(['occurred_at' => '2026-10-01 00:15']);
        $this->assertSame('2026-09-30T12:15:00Z', $record['occurred_at']);
        $this->assertSame('2026-10-01T00:15:00+12:00', $record['occurred_local']);

        $this->getJson('/api/v1/sender-remittances?period=all')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.occurred_local', '2026-10-01T00:15:00+12:00');

        $this->getJson("/api/v1/sender-remittances/{$record['id']}")
            ->assertOk()
            ->assertJsonPath('data.occurred_local', '2026-10-01T00:15:00+12:00');
    }

    public function test_copy_info_has_the_message_fields_and_nothing_secret(): void
    {
        $mother = $this->recipient();
        $r = $this->senderRemittance(['recipient_id' => $mother['id'], 'recipient_type' => 'family', 'method' => 'bkash']);

        $this->getJson("/api/v1/sender-remittances/{$r['id']}/copy-info")->assertExactJson([
            'success' => true, 'code' => 'OK', 'message' => 'OK',
            'data'    => ['recipient_name' => 'আম্মা', 'recipient_type' => 'family', 'family_bdt' => '25625.00', 'method'                     => 'bKash',
                'amount'                                   => '500.00', 'currency'      => 'FJD', 'occurred_at'   => '2026-09-20T06:30:00Z', 'occurred_local' => '2026-09-20 18:30'],
        ]);
    }

    public function test_a_deleted_recipient_keeps_its_name_on_old_remittances(): void
    {
        $mother = $this->recipient();
        $r = $this->senderRemittance(['recipient_id' => $mother['id'], 'recipient_type' => 'family']);
        $this->deleteJson("/api/v1/sender-recipients/{$mother['id']}")->assertOk();

        $this->getJson("/api/v1/sender-remittances/{$r['id']}")->assertJsonPath('data.recipient.name', 'আম্মা')->assertJsonPath('data.recipient.deleted', true);
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['recipient_id' => $mother['id'], 'recipient_type' => 'family']))
            ->assertStatus(422)->assertJsonPath('error_codes.recipient_id.0', 'recipient_required');
        $this->postJson("/api/v1/sender-recipients/{$mother['id']}/restore")->assertOk();
    }

    public function test_sender_records_are_private_even_from_the_business_owner(): void
    {
        $mother = $this->recipient();
        $r = $this->senderRemittance();
        $goal = $this->postJson('/api/v1/savings-goals', ['name' => 'Car', 'target_bdt' => '1000'])->json('data');

        $this->login($this->owner);
        $this->getJson('/api/v1/sender-recipients')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/sender-remittances')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/savings-goals')->assertJsonPath('data.items', []);
        $this->getJson('/api/v1/reports/sender?period=all')->assertJsonPath('data.times_sent', 0);

        foreach ([
            ['get', "/api/v1/sender-recipients/{$mother['id']}"], ['delete', "/api/v1/sender-recipients/{$mother['id']}"],
            ['get', "/api/v1/sender-remittances/{$r['id']}"], ['patch', "/api/v1/sender-remittances/{$r['id']}"],
            ['post', "/api/v1/sender-remittances/{$r['id']}/received"], ['get', "/api/v1/sender-remittances/{$r['id']}/copy-info"],
            ['delete', "/api/v1/savings-goals/{$goal['id']}"],
        ] as [$verb, $url]) {
            $this->json($verb, $url, ['rate' => '1'])->assertStatus(404);
        }

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['goal_id' => $goal['id']]))
            ->assertStatus(422)->assertJsonPath('error_codes.goal_id.0', 'goal_not_found');
    }

    public function test_d1_and_filters(): void
    {
        $id = $this->uuid();
        $this->senderRemittance(['id' => $id]);
        $this->postJson('/api/v1/sender-remittances', $this->senderPayload(['id' => $id, 'amount' => '1']))->assertOk()->assertJsonPath('data.amount', '500.00');

        $mother = $this->recipient();
        $this->senderRemittance(['recipient_id' => $mother['id'], 'recipient_type' => 'family', 'occurred_at' => '2026-08-05 10:00']);

        $this->getJson('/api/v1/sender-remittances?recipient_type=self')->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/v1/sender-remittances?recipient_id={$mother['id']}")->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/sender-remittances?from=2026-09-01&to=2026-09-30')->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/sender-recipients?q=8111')->assertJsonPath('data.meta.total', 1);
    }
}
