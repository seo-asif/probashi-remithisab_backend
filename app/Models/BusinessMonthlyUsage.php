<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMonthlyUsage extends Model
{
    protected $table = 'business_monthly_usage';

    protected $fillable = [
        'business_id',
        'period_start',
        'manager_transactions_used',
        'manager_transaction_bonus',
        'edits_used',
    ];

    protected function casts(): array
    {
        return [
            'period_start'               => 'immutable_date',
            'manager_transactions_used'  => 'integer',
            'manager_transaction_bonus'  => 'integer',
            'edits_used'                 => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}