<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Sender savings goal ("Build a house"). Remittances linked to it count toward it. Private. */
class SavingsGoal extends Model
{
    use BelongsToOwner, HasPublicUuid, SoftDeletes;

    protected $fillable = ['uuid', 'name', 'target_bdt', 'target_date'];

    protected function casts(): array
    {
        return ['target_date' => 'date:Y-m-d'];
    }
}
