<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentFee extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $table = 'student_fees';

    protected $fillable = ['uuid', 'name', 'amount', 'currency', 'due_on', 'paid_on'];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d'];
    }

}
