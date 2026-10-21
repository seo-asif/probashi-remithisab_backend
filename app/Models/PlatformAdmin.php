<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\PlanPaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformAdmin extends Model
{
    protected $fillable = [
        'user_id',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedPaymentRequests(): HasMany
    {
        return $this->hasMany(
            PlanPaymentRequest::class,
            'decided_by_platform_admin_id',
        );
    }
}