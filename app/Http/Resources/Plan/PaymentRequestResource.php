<?php

namespace App\Http\Resources\Plan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'product' => $this->product_type->value,
            'package_months' => $this->package_months,
            'amount_bdt' => $this->expected_amount_bdt,
            'trx_id' => $this->trx_id,
            'payer_number' => $this->payer_number,
            'status' => $this->status->value,
            'rejection_reason' => $this->decision_reason,
            'submitted_at' => $this->created_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'applied_at' => $this->applied_at?->toISOString(),
        ];
    }
}