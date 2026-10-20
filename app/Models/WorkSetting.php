<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** One row per user: pay per hour (optional), normal hours per day, overtime multiplier. Private. */
class WorkSetting extends Model
{
    use BelongsToOwner;

    protected $fillable = ['hourly_rate', 'currency', 'day_hours', 'ot_multiplier'];
}
