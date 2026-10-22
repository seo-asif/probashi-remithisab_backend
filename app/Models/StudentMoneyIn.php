<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentMoneyIn extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $table = 'student_money_in';

    protected $fillable = [
        'uuid', 'from_recipient_id', 'from_name', 'amount_bdt',
        'rate_bdt_per_currency', 'estimate_currency', 'method',
        'received_on', 'note',
    ];

    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d'];
    }

    public function fromRecipient(): BelongsTo
    {
        return $this->belongsTo(SenderRecipient::class, 'from_recipient_id')->withTrashed();
    }
}
