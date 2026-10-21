<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMonthlyUsage extends Model
{
    protected $table = 'user_monthly_usage';

    protected $fillable = [
        'business_id',
        'user_id',
        'period_start',
        'sender_remittances_used',
        'sender_remittance_bonus',
    ];

    protected function casts(): array
    {
        return [
            'period_start'              => 'immutable_date',
            'sender_remittances_used'   => 'integer',
            'sender_remittance_bonus'   => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}