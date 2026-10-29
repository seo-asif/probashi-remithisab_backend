<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAppSetting extends Model
{
    protected $fillable = [
        'user_id', 'language', 'mode', 'default_currency', 'is_student',
        'intro_seen', 'features', 'reminders',
    ];

    protected function casts(): array
    {
        return [
            'is_student' => 'boolean',
            'intro_seen' => 'boolean',
            'features' => 'array',
            'reminders' => 'array',
        ];
    }
}
