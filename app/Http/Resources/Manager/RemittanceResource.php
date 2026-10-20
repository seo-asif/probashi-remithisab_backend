<?php

namespace App\Http\Resources\Manager;

use App\Support\Manager\ManagerCalculator;
use App\Support\Money\Dec;
use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ManagerRemittance */
class RemittanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calc   = ManagerCalculator::remittance($this->method, $this->amount, $this->rate, $this->charge, $this->charge_type, (bool) $this->incentive, $this->incentive_pct, $this->remit_bdt);
        $amount = Dec::of($this->amount);

        return [
            'id'             => $this->uuid,
            'method'         => $this->method,
            'currency'       => $this->currency,
            'amount'         => Dec::money($this->amount),
            'rate'           => Dec::money($this->rate),
            'charge'         => Dec::money($this->charge),
            'charge_type'    => $this->charge_type,
            'incentive'      => (bool) $this->incentive,
            'incentive_pct'  => Dec::money($this->incentive_pct),
            'principal'      => $calc['principal'],
            'base_bdt'       => $calc['base_bdt'],         // breakdown only
            'incentive_bdt'  => $calc['incentive_bdt'],    // breakdown only
            'remit_bdt'      => Dec::money($this->remit_bdt),   // BDT (হিসাব অনুযায়ী)
            'effective_rate' => bccomp($amount, '0', 2) > 0 ? Dec::round(bcdiv(Dec::of($this->remit_bdt), $amount, 8), 2) : null,
            'occurred_at'    => BusinessClock::utcIso($this->occurred_at),
            'timezone'       => $this->timezone,
            'notes'          => $this->notes,
            'created_at'     => BusinessClock::utcIso($this->created_at),
            'updated_at'     => BusinessClock::utcIso($this->updated_at),
            'deleted_at'     => $this->deleted_at ? BusinessClock::utcIso($this->deleted_at) : null,
        ];
    }
}
