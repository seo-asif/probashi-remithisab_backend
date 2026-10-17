<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Models\PasswordResetTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** After the reset code is verified the app gets a one-time ticket, and sends it with the new password. */
class PasswordResetService
{
    /** @return string the ticket (shown once; only its hash is stored) */
    public function issueTicket(User $user): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($user, $token) {
            PasswordResetTicket::query()->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

            PasswordResetTicket::create([
                'user_id'    => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes((int) config('remit.otp.reset_ticket_ttl_minutes')),
            ]);
        });

        return $token;
    }

    /** All or nothing: ticket used, password changed, every login token removed. Returns false if the ticket is not valid. */
    public function reset(User $user, string $token, string $newPassword): bool
    {
        return DB::transaction(function () use ($user, $token, $newPassword) {
            $ticket = PasswordResetTicket::query()
                ->where('user_id', $user->id)
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (!$ticket || $ticket->expires_at->isPast()) {
                return false;
            }

            $ticket->forceFill(['used_at' => now()])->save();

            $user->forceFill(['password' => $newPassword])->save();   // hashed by the model cast

            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();                          // they just proved they own the inbox
            }

            $user->tokens()->delete();

            PasswordResetTicket::query()->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            OtpCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            return true;
        });
    }
}
