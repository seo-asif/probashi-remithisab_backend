<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The operator's own wallet number used to pay customers. */
class SendingNumber extends Model
{
    use BelongsToBusiness, HasPublicUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'number',
        'number_hash',
        'label',
    ];

    protected function casts(): array
{
    return [
        'number' => 'encrypted',
    ];
}
}
