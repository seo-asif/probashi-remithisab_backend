<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class SpendingEntry extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'spent_on', 'category', 'amount', 'currency', 'note'];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d'];
    }
}
