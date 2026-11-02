<?php

namespace App\Support\Admin;

use InvalidArgumentException;

final class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::decode($secret);
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $hmac = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hmac[19]) & 15;
        $value = (unpack('N', substr($hmac, $offset, 4))[1] & 0x7fffffff) % 1000000;
        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public static function matchingStep(string $secret, string $code, int $now, ?int $lastStep): ?int
    {
        if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
            return null;
        }
        $step = intdiv($now, 30);
        foreach ([$step - 1, $step, $step + 1] as $candidate) {
            if ($candidate < 0 || ($lastStep !== null && $candidate <= $lastStep)) {
                continue;
            }
            if (hash_equals(self::code($secret, $candidate), $code)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $letter) {
            $position = strpos($alphabet, $letter);
            if ($position === false) {
                throw new InvalidArgumentException('Invalid TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }
        return $output;
    }
}
