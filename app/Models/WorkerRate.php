<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** First rate has null effective_from and applies to all earlier work days. */
class WorkerRate extends Model
{
    use BelongsToOwner;

    protected $fillable = ['hourly_rate', 'currency', 'effective_from'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d'];
    }
}
