<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasFactory;

    // status is not mass assignable: only server code changes it
    protected $fillable = ['name', 'phone', 'country_code', 'timezone'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            $business->uuid ??= (string) Str::uuid();

            if (empty($business->timezone)) {
                $business->timezone = config('remit.default_business_timezone');
            }
        });
    }

    /** The one timezone every user of this business sees. Stored as an IANA name. */
    protected function timezone(): Attribute
    {
        return Attribute::make(set: function (?string $value) {
            if ($value !== null && !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException("Unknown timezone: {$value}");
            }

            return $value;
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }
}
