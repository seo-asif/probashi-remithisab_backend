<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Business */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->uuid,      // the app never sees the numeric id
            'name'         => $this->name,
            'phone'        => $this->phone,
            'country_code' => $this->country_code,
            'timezone'     => $this->timezone,
            'status'       => $this->status->value,
        ];
    }
}
