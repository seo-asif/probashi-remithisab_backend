<?php

namespace App\Http\Resources\Manager;

use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->uuid,
            'name'           => $this->name,
            'phone'          => $this->phone,
            'address'        => $this->address,
            'notes'          => $this->notes,
            'attention'      => (bool) $this->attention,
            'bkash_numbers'  => array_values($this->bkash_numbers ?? []),
            'nagad_numbers'  => array_values($this->nagad_numbers ?? []),
            'rocket_numbers' => array_values($this->rocket_numbers ?? []),
            'banks'          => array_values($this->banks ?? []),
            'created_at'     => BusinessClock::utcIso($this->created_at),
            'updated_at'     => BusinessClock::utcIso($this->updated_at),
            'deleted_at'     => $this->deleted_at ? BusinessClock::utcIso($this->deleted_at) : null,
            'archived'       => (bool) $this->archived,
            'archived_at'    => $this->archived_at ? BusinessClock::utcIso($this->archived_at) : null,
        ];
    }

    /** Short form used inside duplicate and restore answers. */
    public static function brief($customer): array
    {
        return [
            'id'         => $customer->uuid,
            'name'       => $customer->name,
            'phone'      => $customer->phone,
            'deleted_at' => $customer->deleted_at ? BusinessClock::utcIso($customer->deleted_at) : null,
        ];
    }
}
