<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentExpense extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $table = 'student_expenses';

    protected $fillable = [
        'uuid', 'category', 'amount',
        'currency', 'spent_on', 'note',
    ];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d'];
    }

}
