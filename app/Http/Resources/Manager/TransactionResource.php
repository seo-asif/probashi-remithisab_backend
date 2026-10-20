<?php

namespace App\Http\Resources\Manager;

use App\Support\Money\Dec;
use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ManagerTransaction
 * Money is always a string with 2 decimals. Times are UTC ("Z") plus the timezone they were entered in.
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->relationLoaded('customer') ? $this->customer : null;
        $owed = bcsub(Dec::of($this->obligation_bdt), Dec::of($this->paid_bdt), 2);

        return [
            'id'                => $this->uuid,
            'customer'          => ['id' => $customer?->uuid, 'name' => $this->customer_name, 'phone' => $customer?->phone, 'deleted' => (bool) $customer?->trashed()],
            'currency'          => $this->currency,
            'amount'            => Dec::money($this->amount),
            'rate'              => Dec::money($this->rate),
            'commission'        => Dec::money($this->commission),
            'customer_pays'     => bcadd(Dec::of($this->amount), Dec::of($this->commission), 2), // গ্রাহক মোট দেবেন
            'receive_method'    => $this->receive_method,
            'pay_to'            => $this->pay_to,
            'bank'              => $this->bank,
            'withdrawal_charge' => Dec::money($this->withdrawal_charge),
            'sending_number'    => $this->sending_number,
            'status'            => $this->status,
            'base_bdt'          => Dec::money($this->base_bdt),
            'obligation_bdt'    => Dec::money($this->obligation_bdt), // পাঠাতে হবে
            'paid_bdt'          => Dec::money($this->paid_bdt), // দেওয়া হয়েছে
            'owed_bdt'          => $owed, // এখনও দিতে হবে
            'payments'          => $this->relationLoaded('payments') ? PaymentResource::collection($this->payments)->resolve($request) : null,
            'occurred_at'       => BusinessClock::utcIso($this->occurred_at),
            'timezone'          => $this->timezone,
            'created_at'        => BusinessClock::utcIso($this->created_at),
            'updated_at'        => BusinessClock::utcIso($this->updated_at),
            'deleted_at'        => $this->deleted_at ? BusinessClock::utcIso($this->deleted_at) : null,
        ];
    }
}
