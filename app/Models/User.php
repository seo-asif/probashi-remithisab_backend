<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Email verification uses OTP codes (OtpService), not Laravel's signed-link notification,
 * so this class does NOT implement the MustVerifyEmail contract. hasVerifiedEmail() and
 * markEmailAsVerified() still exist through the base class.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // status and last_login_at are not mass assignable
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Persist the public support number once after primary key allocation.
     * It is an identifier, never an authentication credential.
     */
    protected static function booted(): void
    {
        static::created(function (self $user): void {
            $user->forceFill([
                'member_no' => 10000 + (int) $user->getKey(),
            ])->saveQuietly();
        });

        static::updating(function (self $user): void {
            if ($user->isDirty('member_no')) {
                throw new \LogicException('Member number cannot be changed.');
            }
        });
    }

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',   // null for Google-only accounts
            'status'            => AccountStatus::class,
        ];
    }

    /** Emails are always stored trimmed and lower case. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
