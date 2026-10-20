<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** One timesheet day: work (hours), holiday or sick (typed pay), or off. Private. */
class WorkDay extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'work_date', 'kind', 'hours', 'amount', 'note'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d'];
    }
}
