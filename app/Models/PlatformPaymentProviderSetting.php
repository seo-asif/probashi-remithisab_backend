<?php

namespace App\Models;

use App\Enums\PlanPaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPaymentProviderSetting extends Model
{
    protected $fillable = [
        'provider',
        'is_enabled',
        'public_account_identifier',
        'updated_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'provider'   => PlanPaymentProvider::class,
            'is_enabled' => 'boolean',
        ];
    }

    public function updatedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(
            PlatformAdmin::class,
            'updated_by_platform_admin_id',
        );
    }
}
