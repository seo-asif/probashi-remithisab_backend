<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminMfaCredential extends Model
{
    protected $table = 'platform_admin_mfa_credentials';

    protected $fillable = ['platform_admin_id', 'secret', 'last_used_step'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'last_used_step' => 'integer'];
    }
}
