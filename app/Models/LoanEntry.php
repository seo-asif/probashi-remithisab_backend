<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** take (+), cut (- from a salary), repay (- cash). Amount is always positive. */
class LoanEntry extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'type', 'amount', 'entry_date', 'salary_id', 'note'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d'];
    }
}
