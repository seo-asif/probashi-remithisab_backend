<?php

namespace Tests\Support;

use App\Enums\BusinessRole;
use App\Mail\OtpMail;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

trait AuthHelpers
{
    protected function registerPayload(array $override = []): array
    {
        return $override + [
            'name'                  => 'Asif Rahman',
            'email'                 => 'asif@example.com',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
            'business_name'         => 'Asif Remit',
            'business_phone'        => '01712345678',
            'country_code'          => 'FJ',
            'timezone'              => 'Pacific/Fiji',
        ];
    }

    /** A verified user who owns one active business. */
    protected function makeUser(array $attributes = [], string $password = 'Secret123'): User
    {
        $user = User::factory()->create($attributes + ['email' => 'owner@example.com', 'password' => $password]);

        BusinessMembership::create([
            'business_id' => Business::factory()->create()->id,
            'user_id'     => $user->id,
            'role'        => BusinessRole::Owner,
        ]);

        return $user;
    }

    protected function makeUnverifiedUser(array $attributes = []): User
    {
        return $this->makeUser($attributes + ['email_verified_at' => null]);
    }

    /** @return Collection<int, OtpMail> */
    protected function otpMails(): Collection
    {
        return Mail::sent(OtpMail::class);
    }

    protected function lastOtp(): string
    {
        return $this->otpMails()->last()->code;
    }

    /** Sends the next request with this token. Forgets the cached user, so a token can be tested after it is revoked. */
    protected function asToken(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($plainTextToken);
    }
}
