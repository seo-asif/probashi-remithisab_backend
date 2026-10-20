<?php

namespace App\Support\Security;

use App\Support\Manager\ManagerValidation;
use InvalidArgumentException;

final class IdentifierNormalizer
{
    /**
     * Normalize an already validated customer phone.
     */
    public static function customerPhone(string $phone): string
    {
        $normalized = ManagerValidation::phoneKey($phone);

        if ($normalized === null) {
            throw new InvalidArgumentException(
                'Customer phone cannot be empty.'
            );
        }

        return $normalized;
    }

    /**
     * Normalize an already validated wallet or sending number.
     *
     * Preserves 11- and 12-digit local formats.
     */
    public static function wallet(string $number): string
    {
        $clean = ManagerValidation::cleanWallet($number);

        if (!is_string($clean) || $clean === '') {
            throw new InvalidArgumentException(
                'Wallet number cannot be empty.'
            );
        }

        $digits = ltrim($clean, '+');

        if (!preg_match('/\A[0-9]+\z/D', $digits)) {
            throw new InvalidArgumentException(
                'Wallet number must contain digits.'
            );
        }

        if (str_starts_with($digits, '880')) {
            return '0' . substr($digits, 3);
        }

        return $digits;
    }

    /**
     * Normalize an already validated bank account number.
     *
     * Never cast account numbers to integers.
     */
    public static function bankAccount(string $number): string
    {
        $clean = preg_replace('/[\s-]+/', '', $number);

        if ($clean === '' ||
            !preg_match('/\A[0-9]+\z/D', $clean)) {
            throw new InvalidArgumentException(
                'Invalid bank account number.'
            );
        }

        return $clean;
    }
}
