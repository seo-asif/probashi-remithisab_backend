<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessReferralProfile extends Model
{
    protected $fillable = [
        'business_id',
        'invite_code',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}