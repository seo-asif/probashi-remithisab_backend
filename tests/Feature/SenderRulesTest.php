<?php

namespace Tests\Feature;

use App\Http\Requests\Sender\FamilyMemberRequest;
use App\Http\Requests\Sender\SenderRemittanceRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Needs no database: only the field rules are exercised (the family-member lookup rule is tested in Phase 3). */
class SenderRulesTest extends TestCase
{
    private function fails(array $rules, array $data, string $field): bool
    {
        return Validator::make($data, $rules)->errors()->has($field);
    }

    private function remittance(array $override = []): array
    {
        return $override + [
            'currency' => 'FJD', 'amount' => '100.00', 'rate' => '72.50', 'payment_mode' => 'bKash',
            'charge' => '2.00', 'remit_date' => '2026-10-12', 'notes' => 'ok',
        ];
    }

    public function test_family_member_rules(): void
    {
        $rules = FamilyMemberRequest::ruleSet(false);

        $this->assertFalse(Validator::make(['name' => 'Mother', 'relationship' => 'Mother', 'phone' => '+8801712345678', 'notes' => 'x'], $rules)->fails());
        $this->assertFalse(Validator::make(['name' => 'Rahim'], $rules)->fails());            // everything else is optional

        $this->assertTrue($this->fails($rules, [], 'name'));                                    // name required
        $this->assertTrue($this->fails($rules, ['name' => str_repeat('a', 61)], 'name'));       // max 60
        $this->assertTrue($this->fails($rules, ['name' => 'A', 'relationship' => 'Cousin'], 'relationship'));
        $this->assertTrue($this->fails($rules, ['name' => 'A', 'phone' => 'abc'], 'phone'));
        $this->assertTrue($this->fails($rules, ['name' => 'A', 'phone' => str_repeat('1', 19)], 'phone'));
        $this->assertTrue($this->fails($rules, ['name' => 'A', 'notes' => str_repeat('n', 101)], 'notes'));
    }

    public function test_family_member_has_no_payment_fields(): void
    {
        $rules = FamilyMemberRequest::ruleSet(false);

        foreach (['payment_methods', 'bkash', 'nagad', 'rocket', 'bank', 'payment', 'method'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $rules, $legacy);
        }
    }

    public function test_a_valid_remittance_passes(): void
    {
        $this->assertFalse(Validator::make($this->remittance(), SenderRemittanceRequest::baseRules(false))->fails());
        $this->assertFalse(Validator::make($this->remittance(['charge' => null, 'notes' => null]), SenderRemittanceRequest::baseRules(false))->fails());
    }

    public function test_remittance_amount_rate_and_charge_limits(): void
    {
        $rules = SenderRemittanceRequest::baseRules(false);

        foreach (['0', '0.00', '-5', '0.001', '10000000.01', 'abc'] as $bad) {
            $this->assertTrue($this->fails($rules, $this->remittance(['amount' => $bad]), 'amount'), "amount $bad");
        }
        foreach (['0.01', '10000000'] as $good) {
            $this->assertFalse($this->fails($rules, $this->remittance(['amount' => $good]), 'amount'), "amount $good");
        }
        foreach (['0', '0.001', '10000.01', '72.12345'] as $bad) {
            $this->assertTrue($this->fails($rules, $this->remittance(['rate' => $bad]), 'rate'), "rate $bad");
        }
        $this->assertFalse($this->fails($rules, $this->remittance(['rate' => '72.1234']), 'rate'));
        $this->assertTrue($this->fails($rules, $this->remittance(['charge' => '-1']), 'charge'));
        $this->assertTrue($this->fails($rules, $this->remittance(['charge' => '1.234']), 'charge'));
    }

    public function test_remittance_choices_and_formats(): void
    {
        $rules = SenderRemittanceRequest::baseRules(false);

        $this->assertTrue($this->fails($rules, $this->remittance(['currency' => 'QAR']), 'currency'));   // not in the Sender form
        $this->assertFalse($this->fails($rules, $this->remittance(['currency' => 'AED']), 'currency'));
        $this->assertTrue($this->fails($rules, $this->remittance(['payment_mode' => 'Cash']), 'payment_mode'));
        $this->assertTrue($this->fails($rules, $this->remittance(['payment_mode' => 'bkash']), 'payment_mode')); // exact spelling, as in the app
        $this->assertTrue($this->fails($rules, $this->remittance(['remit_date' => '12/10/2026']), 'remit_date'));
        $this->assertTrue($this->fails($rules, $this->remittance(['remit_date' => '2026-02-30']), 'remit_date'));
        $this->assertTrue($this->fails($rules, $this->remittance(['notes' => str_repeat('n', 101)]), 'notes'));
    }

    public function test_required_fields_on_create_and_optional_on_edit(): void
    {
        $this->assertTrue($this->fails(SenderRemittanceRequest::baseRules(false), [], 'amount'));
        $this->assertTrue($this->fails(SenderRemittanceRequest::baseRules(false), [], 'rate'));
        $this->assertTrue($this->fails(SenderRemittanceRequest::baseRules(false), [], 'remit_date'));

        $this->assertFalse(Validator::make(['notes' => 'only a note'], SenderRemittanceRequest::baseRules(true))->fails());
    }

    public function test_the_app_can_never_send_the_total(): void
    {
        $rules = SenderRemittanceRequest::baseRules(false);

        $this->assertArrayNotHasKey('total_bdt', $rules);   // total BDT is computed by the server only
        $this->assertArrayNotHasKey('business_id', $rules);
        $this->assertArrayNotHasKey('owner_user_id', $rules);
    }
}
