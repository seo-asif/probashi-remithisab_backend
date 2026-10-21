<?php

namespace App\Models;

use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPaymentRequest extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'uuid',
        'business_id',
        'submitted_by_user_id',
        'beneficiary_user_id',
        'product_type',
        'package_months',
        'expected_amount_bdt',
        'trx_id',
        'trx_id_hash',
        'payer_number',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => PlanPaymentProduct::class,
            'status' => PlanPaymentStatus::class,

            'package_months' => 'integer',
            'expected_amount_bdt' => 'integer',

            /*
             * These contain sensitive payment identifiers.
             * Plaintext must never be stored in the database.
             */
            'trx_id' => 'encrypted',
            'payer_number' => 'encrypted',

            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'submitted_by_user_id',
        );
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'beneficiary_user_id',
        );
    }

    public function decidedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(
            PlatformAdmin::class,
            'decided_by_platform_admin_id',
        );
    }
}