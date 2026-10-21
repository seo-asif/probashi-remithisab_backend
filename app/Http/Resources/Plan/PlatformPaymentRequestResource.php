<?php

namespace App\Http\Resources\Plan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformPaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            'business' => [
                'id' => $this->business?->uuid,
                'name' => $this->business?->name,
            ],

            'submitted_by' => [
                'name' => $this->submittedBy?->name,
                'email' => $this->submittedBy?->email,
            ],

            'product' => $this->product_type->value,
            'package_months' => $this->package_months,
            'amount_bdt' => $this->expected_amount_bdt,

            /*
             * Platform administrators need these values to verify
             * the manual bKash payment.
             *
             * They are decrypted here only after platform.admin
             * authorization has succeeded.
             */
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