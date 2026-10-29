<?php

namespace Tests\Feature\Sender;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class SenderRecipientSummaryTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_empty_recipient_has_zero_summary_on_list_and_detail(): void
    {
        $recipient = $this->recipient();
        $list = $this->getJson('/api/v1/sender-recipients')->assertOk()->json('data.items.0.summary');
        $detail = $this->getJson('/api/v1/sender-recipients/' . $recipient['id'])->assertOk()->json('data.summary');

        $this->assertSame($detail, $list);
        $this->assertSame(0, $detail['remittance_count']);
        $this->assertSame([], $detail['by_currency']);
        $this->assertSame('0.00', $detail['family_bdt']);
        $this->assertNull($detail['last_sent_at']);
    }

    public function test_summary_counts_only_active_records_for_correct_recipient(): void
    {
        $first = $this->recipient();
        $second = $this->recipient(['name' => 'Second', 'phone' => '01822222222']);
        $a = $this->senderRemittance(['recipient_type' => 'family', 'recipient_id' => $first['id'], 'amount' => '100.25']);
        $b = $this->senderRemittance(['recipient_type' => 'family', 'recipient_id' => $first['id'], 'amount' => '200.25']);
        $this->senderRemittance(['recipient_type' => 'family', 'recipient_id' => $second['id'], 'amount' => '700']);
        $this->senderRemittance(['recipient_type' => 'self', 'amount' => '900']);
        $this->deleteJson('/api/v1/sender-remittances/' . $b['id'])->assertOk();

        $summary = $this->getJson('/api/v1/sender-recipients/' . $first['id'])->assertOk()->json('data.summary');
        $this->assertSame(1, $summary['remittance_count']);
        $this->assertSame([['currency' => 'FJD', 'total_sent' => '100.25']], $summary['by_currency']);
        $this->assertSame($a['family_bdt'], $summary['family_bdt']);
        $this->assertNotNull($summary['last_sent_at']);
        $this->assertNotNull($summary['last_sent_local']);
    }

    public function test_other_business_records_are_never_included(): void
    {
        $first = $this->recipient();
        $this->senderRemittance(['recipient_type' => 'family', 'recipient_id' => $first['id']]);
        $this->actAs($this->makeBusiness());
        $this->getJson('/api/v1/sender-recipients/' . $first['id'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/sender-recipients')->assertOk()->json('data.items'));
    }
}
