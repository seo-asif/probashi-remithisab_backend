<?php

namespace App\Support\Plans;

final class BangladeshMobileNumber
{
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        /*
         * 8801712345678 -> 01712345678
         */
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        /*
         * 1712345678 -> 01712345678
         */
        if (
            strlen($digits) === 10
            && str_starts_with($digits, '1')
        ) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(
            '/\A01[3-9]\d{8}\z/D',
            $value,
        ) === 1;
    }
}
