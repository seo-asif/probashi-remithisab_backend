<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One payment of taka to a customer's family. Never edited; corrected by a reversal entry (negative amount). */
class BdtPayment extends Model
{
    use BelongsToBusiness, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uuid', 'user_id', 'transaction_id', 'amount_bdt', 'paid_on', 'auto', 'reverses_payment_id', 'note'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'paid_on' => 'date:Y-m-d'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ManagerTransaction::class, 'transaction_id');
    }
}
