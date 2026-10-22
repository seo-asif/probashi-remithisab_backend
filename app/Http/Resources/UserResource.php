<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'member_no'      => $this->member_no === null ? null : (int) $this->member_no,
            'email'          => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'status'         => $this->status->value,
        ];
    }
}
