<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessReferral extends Model
{
    use HasPublicUuid;

    /*
     * Only fields required to establish the referral relationship
     * are mass assignable.
     *
     * Qualification fields are intentionally excluded because only
     * trusted server-side qualification logic may change them.
     */
    protected $fillable = [
        'uuid',
        'inviter_business_id',
        'referred_business_id',
        'claimed_at',
    ];

    protected $attributes = [
        'status' => 'claimed',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'claimed_at' => 'immutable_datetime',
            'referred_trial_granted_at' => 'immutable_datetime',
            'qualified_at' => 'immutable_datetime',
            'qualification_sequence' => 'integer',
        ];
    }

    public function inviterBusiness(): BelongsTo
    {
        return $this->belongsTo(
            Business::class,
            'inviter_business_id',
        );
    }

    public function referredBusiness(): BelongsTo
    {
        return $this->belongsTo(
            Business::class,
            'referred_business_id',
        );
    }

    public function qualifyingPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(
            PlanPaymentRequest::class,
            'qualifying_payment_request_id',
        );
    }

    public function reward(): HasOne
    {
        return $this->hasOne(
            ReferralReward::class,
            'referral_id',
        );
    }
}