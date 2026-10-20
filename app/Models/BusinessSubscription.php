<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSubscription extends Model
{
    protected $fillable = [
        'business_id',
        'tier',
        'access_source',
        'access_starts_at',
        'access_ends_at',
        'trial_used_at',
    ];

    protected function casts(): array
    {
        return [
            'access_starts_at' => 'immutable_datetime',
            'access_ends_at'   => 'immutable_datetime',
            'trial_used_at'    => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}