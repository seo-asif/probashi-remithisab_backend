<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['business_id', 'actor_user_id', 'action', 'target_type', 'target_id', 'metadata', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
