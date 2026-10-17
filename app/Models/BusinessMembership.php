<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's explicit membership of a business. The only source of "which business is this user in". */
class BusinessMembership extends Model
{
    protected $table = 'business_memberships';

    protected $fillable = ['business_id', 'user_id', 'role', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'role'   => BusinessRole::class,
            'status' => AccountStatus::class,
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
