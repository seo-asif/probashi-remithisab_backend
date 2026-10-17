<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    public const PURPOSE_VERIFY_EMAIL   = 'verify_email';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $fillable = ['user_id', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'invalidated_at', 'ip'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at'     => 'datetime',
            'consumed_at'    => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
