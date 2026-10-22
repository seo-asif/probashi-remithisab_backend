<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentExpense extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $table = 'student_expenses';

    protected $fillable = [
        'uuid', 'student_fee_id', 'category', 'amount',
        'currency', 'spent_on', 'note',
    ];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id')->withTrashed();
    }
}
