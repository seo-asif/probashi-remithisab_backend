<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SenderSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'tier',
        'access_source',
        'access_starts_at',
        'access_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'access_starts_at' => 'immutable_datetime',
            'access_ends_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
