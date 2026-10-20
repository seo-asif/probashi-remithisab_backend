<?php

namespace App\Http\Resources\Manager;

use App\Support\Money\Dec;
use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BdtPayment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,
            'amount_bdt'  => Dec::money($this->amount_bdt),          // negative = a reversal
            'paid_on'     => $this->paid_on?->format('Y-m-d'),
            'auto'        => (bool) $this->auto,
            'reverses_id' => $this->reverses_payment_id ? optional(\App\Models\BdtPayment::withTrashed()->find($this->reverses_payment_id))->uuid : null,
            'note'        => $this->note,
            'created_at'  => BusinessClock::utcIso($this->created_at),
        ];
    }
}
