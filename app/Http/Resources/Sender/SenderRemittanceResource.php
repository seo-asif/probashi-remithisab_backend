<?php

namespace App\Http\Resources\Sender;

use App\Support\Money\Dec;
use App\Support\Sender\SenderCalculator;
use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SenderRemittance */
class SenderRemittanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recipient = $this->relationLoaded('recipient') ? $this->recipient : null;
        $goal      = $this->relationLoaded('goal') ? $this->goal : null;
        $calc      = SenderCalculator::breakdown($this->amount, $this->rate, $this->charge, $this->charge_type, $this->incentive_pct);

        return [
            'id'             => $this->uuid,
            'recipient_type' => $this->recipient_type,
            'recipient'      => ['id' => $recipient?->uuid, 'name' => $this->recipient_name, 'deleted' => (bool) $recipient?->trashed()],
            'currency'       => $this->currency,
            'amount'         => Dec::money($this->amount),
            'rate'           => Dec::of($this->rate, \App\Support\Money\CurrencyRules::rateDecimals($this->currency)),
            'charge'         => Dec::money($this->charge),
            'charge_type'    => $this->charge_type,
            'incentive_pct'  => Dec::money($this->incentive_pct),
            'method'         => $this->method,
            'principal'      => Dec::money($this->principal),
            'base_bdt'       => $calc['base_bdt'],                 // breakdown only
            'incentive_bdt'  => Dec::money($this->incentive_bdt),  // breakdown only
            'family_bdt'     => Dec::money($this->family_bdt),     // পরিবার পাবে
            'total_cost'     => Dec::money($this->total_cost),
            'received'       => (bool) $this->received,            // পরিবার পেয়েছে ✓
            'received_at'    => $this->received_at ? BusinessClock::utcIso($this->received_at) : null,
            'purpose'        => $this->purpose,
            'goal'           => $goal ? ['id' => $goal->uuid, 'name' => $goal->name] : null,
            'notes'          => $this->notes,
            'occurred_at'    => BusinessClock::utcIso($this->occurred_at),
            'timezone'       => $this->timezone,
            'created_at'     => BusinessClock::utcIso($this->created_at),
            'updated_at'     => BusinessClock::utcIso($this->updated_at),
            'deleted_at'     => $this->deleted_at ? BusinessClock::utcIso($this->deleted_at) : null,
        ];
    }
}
