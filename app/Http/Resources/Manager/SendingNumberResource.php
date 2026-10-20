<?php

namespace App\Http\Resources\Manager;

use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SendingNumber */
class SendingNumberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->uuid,
            'number'     => $this->number,
            'label'      => $this->label,
            'updated_at' => BusinessClock::utcIso($this->updated_at),
        ];
    }
}
