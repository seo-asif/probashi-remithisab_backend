<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Jobs\SendOtpEmail;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Auth\OtpCodec;
use Illuminate\Support\Facades\DB;

/**
 * Email one-time codes: 6 digits, 5 minutes, 5 attempts, resend cooldown (all configurable in config/remit.php).
 * The code is never stored: only a keyed hash. It leaves the server only inside an encrypted queued email job.
 */
class OtpService
{
    /**
     * Sends a new code, unless one was created within the cooldown. A new code replaces any older unused one.
     * Returns whether a code was created.
     */
    public function issue(User $user, string $purpose): bool
    {
        $length   = (int) config('remit.otp.length');
        $ttl      = (int) config('remit.otp.ttl_minutes');
        $cooldown = (int) config('remit.otp.resend_cooldown_seconds');
        $code     = OtpCodec::generate($length);
        $hash     = OtpCodec::hash($user->id, $purpose, $code, $this->key());

        $created = DB::transaction(function () use ($user, $purpose, $hash, $ttl, $cooldown) {
            // Lock the user row, so two simultaneous requests cannot both create a code.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $recent = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('created_at', '>', now()->subSeconds($cooldown))
                ->exists();

            if ($recent) {
                return false;
            }

            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            OtpCode::create([
                'user_id'    => $user->id,
                'purpose'    => $purpose,
                'code_hash'  => $hash,
                'attempts'   => 0,
                'expires_at' => now()->addMinutes($ttl),
                'ip'         => request()?->ip(),
            ]);

            return true;
        });

        if ($created) {
            SendOtpEmail::dispatch($user->email, $user->name, $purpose, $code, $ttl);
        }

        return $created;
    }

    /**
     * Checks a code. Safe against simultaneous requests: the row is locked, and a correct code is consumed
     * in the same step, so it can only succeed once. A wrong guess is counted even though we return false.
     */
    public function verify(User $user, string $purpose, string $code): bool
    {
        $maxAttempts = (int) config('remit.otp.max_attempts');
        $key         = $this->key();

        return DB::transaction(function () use ($user, $purpose, $code, $maxAttempts, $key) {
            $row = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$row || $row->expires_at->isPast() || $row->attempts >= $maxAttempts) {
                return false;
            }

            if (OtpCodec::matches($row->code_hash, $user->id, $purpose, $code, $key)) {
                $row->forceFill(['consumed_at' => now()])->save();

                return true;
            }

            $row->increment('attempts');

            return false;
        });
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new ApiException('CONFIGURATION_ERROR', 'The server is not configured correctly.', 503);
        }

        return $key;
    }
}
