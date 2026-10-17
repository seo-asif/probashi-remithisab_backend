<?php

namespace App\Support\Auth;

/** Pure helpers for one-time codes. No database, no framework. */
final class OtpCodec
{
    /** A random code from a cryptographically secure generator, with leading zeros kept. */
    public static function generate(int $length = 6): string
    {
        if ($length < 4 || $length > 10) {
            throw new \InvalidArgumentException('The code length must be between 4 and 10.');
        }

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** Keyed hash. The user and purpose are part of it, so a code is useless for anything else. */
    public static function hash(int $userId, string $purpose, string $code, string $key): string
    {
        if ($key === '') {
            throw new \RuntimeException('The application key is not set.');
        }

        return hash_hmac('sha256', $userId . '|' . $purpose . '|' . $code, $key);
    }

    public static function matches(string $expectedHash, int $userId, string $purpose, string $code, string $key): bool
    {
        return hash_equals($expectedHash, self::hash($userId, $purpose, $code, $key));
    }
}
