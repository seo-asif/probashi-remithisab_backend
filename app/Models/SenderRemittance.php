<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sender remittance to a family member or to "Myself". Private to the user who created it. */
class SenderRemittance extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    public const MYSELF_NAME = 'Myself';

    protected $fillable = [
        'uuid', 'recipient_type', 'recipient_id', 'recipient_name', 'currency', 'amount', 'rate', 'charge', 'charge_type',
        'incentive_pct', 'method', 'principal', 'incentive_bdt', 'family_bdt', 'total_cost', 'received', 'received_at',
        'purpose', 'goal_id', 'notes', 'occurred_at', 'timezone',
    ];

    protected function casts(): array
    {
        return ['received' => 'boolean', 'received_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(SenderRecipient::class, 'recipient_id')->withTrashed();
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'goal_id');
    }
}
