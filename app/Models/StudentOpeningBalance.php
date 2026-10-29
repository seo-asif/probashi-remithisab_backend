<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class StudentOpeningBalance extends Model
{
    use BelongsToOwner, HasPublicUuid;

    protected $fillable = ['uuid', 'currency', 'amount'];
}
