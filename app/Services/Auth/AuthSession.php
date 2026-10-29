<?php

namespace App\Services\Auth;

use App\Http\Resources\BusinessResource;
use App\Http\Resources\UserResource;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Services\Account\AppSettingsService;
use App\Support\Time\BusinessClock;
use Illuminate\Support\Str;

/** Issues a login token (with an expiry) and builds the standard "logged in" response data. */
class AuthSession
{
    public function issue(User $user, BusinessMembership $membership, ?string $deviceName = null): array
    {
        $token = $user->createToken(
            Str::limit(trim((string) $deviceName) !== '' ? trim((string) $deviceName) : 'mobile', 60, ''),
            ['*'],
            now()->addMinutes((int) config('remit.token_ttl_minutes')),
        );

        $user->forceFill(['last_login_at' => now()])->save();

        return [
            'token'      => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => BusinessClock::utcIso($token->accessToken->expires_at),
            'user'       => UserResource::make($user)->resolve(),
            'app_settings' => app(AppSettingsService::class)->snapshot($user, $membership->business),
            'business'   => BusinessResource::make($membership->business)->resolve(),
            'role'       => $membership->role->value,
        ];
    }
}
