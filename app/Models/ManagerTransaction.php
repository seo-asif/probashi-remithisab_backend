<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Manager customer transaction. obligation_bdt and paid_bdt are always computed by the server. */
class ManagerTransaction extends Model
{
    use BelongsToBusiness, HasPublicUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'user_id', 'customer_id', 'customer_name', 'currency', 'amount', 'rate', 'commission', 'receive_method',
        'pay_to', 'destination_hash', 'bank', 'withdrawal_charge', 'sending_number', 'status', 'base_bdt', 'obligation_bdt', 'paid_bdt',
        'occurred_at', 'timezone',
    ];

    protected function casts(): array
    {
        return [
            'pay_to'         => 'encrypted',
            'bank'           => 'encrypted:array',
            'sending_number' => 'encrypted',
            'occurred_at'    => 'immutable_datetime',
            'quota_refunded_at' => 'datetime',
            'quota_refund_reapplied_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Active payment entries (reversals included, removed entries excluded), oldest first. */
    public function payments(): HasMany
    {
        return $this->hasMany(BdtPayment::class, 'transaction_id')->orderBy('id');
    }
}
