<?php

namespace App\Http\Resources\Sender;

use App\Support\Time\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SenderRecipient */
class SenderRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->uuid,
            'name'         => $this->name,
            'relationship' => $this->relationship,
            'phone'        => $this->phone,
            'notes'        => $this->notes,
            'created_at'   => BusinessClock::utcIso($this->created_at),
            'updated_at'   => BusinessClock::utcIso($this->updated_at),
            'deleted_at'   => $this->deleted_at ? BusinessClock::utcIso($this->deleted_at) : null,
        ];
    }
}
