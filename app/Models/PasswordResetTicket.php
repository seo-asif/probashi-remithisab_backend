<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetTicket extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
        ];
    }
}
