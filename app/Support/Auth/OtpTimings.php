<?php

namespace App\Support\Auth;

/** The same timing hints are returned for every email, known or not, so they reveal nothing. */
final class OtpTimings
{
    /** @return array{expires_in_seconds: int, resend_after_seconds: int} */
    public static function data(): array
    {
        return [
            'expires_in_seconds'   => (int) config('remit.otp.ttl_minutes') * 60,
            'resend_after_seconds' => (int) config('remit.otp.resend_cooldown_seconds'),
        ];
    }
}
