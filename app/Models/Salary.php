<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A salary covers the days from_date..to_date. Salaries never overlap. A loan cut is a linked loan entry. */
class Salary extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'from_date', 'to_date', 'amount_received', 'received_on', 'note'];

    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'received_on' => 'date:Y-m-d'];
    }

    public function loanCut(): HasOne
    {
        return $this->hasOne(LoanEntry::class, 'salary_id')->where('type', 'cut');
    }
}
