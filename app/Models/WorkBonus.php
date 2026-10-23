<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class WorkBonus extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'bonus_date', 'amount', 'currency', 'note'];

    protected function casts(): array
    {
        return ['bonus_date' => 'date:Y-m-d'];
    }
}
