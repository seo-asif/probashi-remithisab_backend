<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class StudentSetting extends Model
{
    use BelongsToOwner;

    protected $fillable = ['enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
