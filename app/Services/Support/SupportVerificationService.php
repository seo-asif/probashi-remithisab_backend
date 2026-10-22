<?php

namespace App\Services\Support;

use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Auth\OtpCodec;
use Illuminate\Support\Facades\DB;

class SupportVerificationService
{
    /**
     * Issue a new support verification code.
     *
     * Only the keyed hash is stored in the database.
     * A newer code invalidates any older unused support code.
     */
    public function issue(User $user): array
    {
        $purpose = OtpCode::PURPOSE_SUPPORT_VERIFICATION;

        $length = (int) config('remit.support_verification.length');
        $ttl = (int) config('remit.support_verification.ttl_minutes');
        $cooldown = (int) config(
            'remit.support_verification.resend_cooldown_seconds'
        );

        $code = OtpCodec::generate($length);

        $hash = OtpCodec::hash(
            $user->id,
            $purpose,
            $code,
            $this->key()
        );

        DB::transaction(function () use (
            $user,
            $purpose,
            $hash,
            $ttl,
            $cooldown
        ): void {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $recent = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('created_at', '>', now()->subSeconds($cooldown))
                ->exists();

            if ($recent) {
                throw new ApiException(
                    'SUPPORT_CODE_COOLDOWN',
                    'Please wait before requesting another support verification code.',
                    429
                );
            }

            OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update([
                    'invalidated_at' => now(),
                ]);

            OtpCode::create([
                'user_id'    => $user->id,
                'purpose'    => $purpose,
                'code_hash'  => $hash,
                'attempts'   => 0,
                'expires_at' => now()->addMinutes($ttl),
                'ip'         => request()?->ip(),
            ]);
        });

        return [
            'verification_code'    => $code,
            'expires_in_seconds'   => $ttl * 60,
            'resend_after_seconds' => $cooldown,
        ];
    }

/**
 * Verify and consume a support verification code.
 *
 * A valid code can succeed only once.
 */
    public function verify(User $user, string $code): bool
    {
        $purpose = OtpCode::PURPOSE_SUPPORT_VERIFICATION;

        $maxAttempts = (int) config(
            'remit.support_verification.max_attempts'
        );

        $key = $this->key();

        return DB::transaction(function () use (
            $user,
            $purpose,
            $code,
            $maxAttempts,
            $key
        ): bool {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $row = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (
                $row === null
                || $row->expires_at->isPast()
                || $row->attempts >= $maxAttempts
            ) {
                return false;
            }

            if (
                OtpCodec::matches(
                    $row->code_hash,
                    $user->id,
                    $purpose,
                    $code,
                    $key
                )
            ) {
                $row->forceFill([
                    'consumed_at' => now(),
                ])->save();

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
            throw new ApiException(
                'CONFIGURATION_ERROR',
                'The server is not configured correctly.',
                503
            );
        }

        return $key;
    }
}
