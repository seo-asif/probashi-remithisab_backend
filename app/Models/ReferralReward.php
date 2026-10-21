<?php

namespace App\Models;

use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    use HasPublicUuid;

    /*
     * Reward creation data may be assigned by the trusted referral
     * service. Lifecycle fields are deliberately excluded.
     */
    protected $fillable = [
        'uuid',
        'referral_id',
        'beneficiary_business_id',
        'reward_type',
        'unlimited_days',
        'discount_bdt',
    ];

    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'reward_type' => ReferralRewardType::class,
            'status' => ReferralRewardStatus::class,

            'unlimited_days' => 'integer',
            'discount_bdt' => 'integer',

            'reserved_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(
            BusinessReferral::class,
            'referral_id',
        );
    }

    public function beneficiaryBusiness(): BelongsTo
    {
        return $this->belongsTo(
            Business::class,
            'beneficiary_business_id',
        );
    }

    public function reservedForPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(
            PlanPaymentRequest::class,
            'reserved_for_payment_request_id',
        );
    }

    public function appliedToPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(
            PlanPaymentRequest::class,
            'applied_to_payment_request_id',
        );
    }
}