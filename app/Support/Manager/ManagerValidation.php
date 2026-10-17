<?php

namespace App\Support\Manager;

use App\Support\Validation\Text;

/** Pure validation helpers for Manager mode, worded like app v1.1.250. */
final class ManagerValidation
{
    /** The app's normalizeContactNumber(): keeps a leading + and the digits, drops everything else. */
    public static function cleanContact(mixed $phone): mixed
    {
        if (!is_string($phone)) {
            return $phone;
        }

        $value = trim($phone);

        if ($value === '') {
            return null;
        }

        // only spaces, dashes and brackets are removed; anything else stays so that validation can reject it
        return preg_replace('/[\s\-()]/', '', $value);
    }

    /**
     * Key used to find the same customer again: digits only, and a leading 880 becomes 0
     * (so +8801712345678, 8801712345678 and 01712345678 are the same person). Same rule as the app.
     */
    public static function phoneKey(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $key = preg_replace('/^880/', '0', preg_replace('/\D/', '', $phone));

        return $key === '' ? null : $key;
    }

    /** The app's normalizeWalletInput(): no spaces, + only at the start. */
    public static function cleanWallet(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $v = preg_replace('/\s+/', '', $value);

        return str_starts_with($v, '+')
            ? '+' . preg_replace('/\D/', '', substr($v, 1))
            : preg_replace('/\D/', '', $v);
    }

    /** The app's validateWalletNumber(). Returns the error message or null. */
    public static function walletError(string $value, string $method): ?string
    {
        $isRocket = $method === 'Rocket';
        $name     = $method === 'Bkash' ? 'bKash' : $method;
        $normal   = $isRocket ? [11, 12] : [11, 11];
        $plus88   = $isRocket ? [13, 14] : [13, 13];

        if ($value === '' || !preg_match('/^\+?\d+$/', $value)) {
            return 'Use numbers only; + is allowed only at the beginning.';
        }

        $digits = ltrim($value, '+');
        $len    = strlen($digits);
        $range  = fn (array $r) => $r[0] === $r[1] ? (string) $r[0] : $r[0] . '–' . $r[1];

        if (str_starts_with($digits, '880')) {
            $expected = $normal[0] + 2;

            return $len === $expected && $digits[3] === '1'
                ? null
                : "{$name} number starting with +880 must contain {$expected} digits (for example +8801XXXXXXXXX).";
        }

        if (str_starts_with($digits, '01')) {
            return $len >= $normal[0] && $len <= $normal[1] ? null : "{$name} number starting with 01 must be {$range($normal)} digits.";
        }

        if (str_starts_with($digits, '88')) {
            return $len >= $plus88[0] && $len <= $plus88[1] ? null : "{$name} number starting with 88 must be {$range($plus88)} digits.";
        }

        return "{$name} number must start with +880, 01, or 88.";
    }

    /** The app's validateSendingNumber() (the operator's own sending numbers). */
    public static function sendingNumberError(string $value): ?string
    {
        if ($value === '' || !preg_match('/^\+?\d+$/', $value)) {
            return 'Use numbers only; + is allowed only at the beginning.';
        }

        $digits = ltrim($value, '+');
        $len    = strlen($digits);

        if (str_starts_with($digits, '880')) {
            return $len === 13 && $digits[3] === '1' ? null : 'Number starting with +880 must contain 13 digits (for example +8801XXXXXXXXX).';
        }
        if (str_starts_with($digits, '01')) {
            return $len === 11 ? null : 'Number starting with 01 must be 11 digits.';
        }
        if (str_starts_with($digits, '88')) {
            return $len === 13 ? null : 'Number starting with 88 must be 13 digits.';
        }

        return 'Number must start with +880, 01, or 88.';
    }

    public static function customerNameError(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return 'Customer name is required.';
        }

        $length = Text::graphemeLength($name);

        if ($length < ManagerOptions::CUSTOMER_NAME_MIN) {
            return 'Customer name must be at least ' . ManagerOptions::CUSTOMER_NAME_MIN . ' characters.';
        }
        if ($length > ManagerOptions::CUSTOMER_NAME_MAX) {
            return 'Customer name must be ' . ManagerOptions::CUSTOMER_NAME_MAX . ' characters or fewer.';
        }
        if (Text::containsCode($name) || !preg_match("/^[\\p{L}\\p{M} .\\-']+$/u", $name)) {
            return "Use letters, spaces, . - and ' only.";
        }

        return null;
    }

    public static function addressError(string $address): ?string
    {
        if (mb_strlen($address) > ManagerOptions::ADDRESS_MAX) {
            return 'Address must be 100 characters or fewer.';
        }
        if (Text::containsCode($address) || !preg_match('/^[\p{L}\p{M}\p{N}\s,.\-\/#()]+$/u', $address)) {
            return 'Use plain text with letters, numbers, spaces, , . - / # and ( ).';
        }

        return null;
    }

    /**
     * The app's validateBankField() for one bank account.
     *
     * @param array<string, mixed> $bank account_name, account_number, bank_name, branch, routing_number
     * @return array<string, string> field => message
     */
    public static function bankErrors(array $bank): array
    {
        $errors = [];
        $get    = fn (string $key) => is_string($bank[$key] ?? null) ? trim($bank[$key]) : (is_null($bank[$key] ?? null) ? '' : null);

        $name = $get('account_name');
        if ($name === null || $name === '') {
            $errors['account_name'] = 'Account holder name is required.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 60 || Text::containsCode($name) || !preg_match("/^[\\p{L}\\p{M} .\\-']+$/u", $name)) {
            $errors['account_name'] = "Account holder name: letters, spaces, . - and ' only; maximum 60 characters.";
        }

        $number = $get('account_number');
        if ($number === null || $number === '') {
            $errors['account_number'] = 'Account number is required.';
        } elseif (!preg_match('/^\d+$/', $number)) {
            $errors['account_number'] = 'Account number must contain numbers only.';
        } elseif (strlen($number) < 8) {
            $errors['account_number'] = 'Account number must be at least 8 digits.';
        } elseif (strlen($number) > 30) {
            $errors['account_number'] = 'Account number can be at most 30 digits.';
        }

        $bankName = $get('bank_name');
        if ($bankName === null || $bankName === '') {
            $errors['bank_name'] = 'Bank name is required.';
        } elseif (mb_strlen($bankName) > 60 || Text::containsCode($bankName) || !preg_match('/^[\p{L}\p{M}\p{N} .&\-]+$/u', $bankName)) {
            $errors['bank_name'] = 'Bank name must be plain text and 60 characters or fewer.';
        }

        $branch = $get('branch');
        if ($branch === null || ($branch !== '' && (mb_strlen($branch) > 60 || Text::containsCode($branch) || !preg_match('/^[\p{L}\p{M}\p{N} .&\-\/#()]+$/u', $branch)))) {
            $errors['branch'] = 'Branch name must be plain text and 60 characters or fewer.';
        }

        $routing = $get('routing_number');
        if ($routing === null || ($routing !== '' && !preg_match('/^\d+$/', $routing))) {
            $errors['routing_number'] = 'Routing number must contain numbers only.';
        } elseif ($routing !== '' && strlen($routing) !== 9) {
            $errors['routing_number'] = 'Routing number must be exactly 9 digits.';
        }

        return $errors;
    }

    /** Cleans one bank account the way the app does (account and routing numbers lose spaces and dashes). */
    public static function cleanBank(mixed $bank): mixed
    {
        if (!is_array($bank)) {
            return $bank;
        }

        $out = [];
        foreach (['account_name', 'account_number', 'bank_name', 'branch', 'routing_number'] as $key) {
            $value = $bank[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                if (in_array($key, ['account_number', 'routing_number'], true)) {
                    $value = preg_replace('/[\s\-]+/', '', $value);
                }
            }
            $out[$key] = $value === '' ? null : $value;
        }

        return $out;
    }

    /** The app's bankLabel(): "Bank name · Account name · Account number". */
    public static function bankLabel(array $bank): string
    {
        $parts = array_filter([$bank['bank_name'] ?? null, $bank['account_name'] ?? null, $bank['account_number'] ?? null], fn ($v) => $v !== null && $v !== '');

        return $parts ? implode(' · ', $parts) : 'Bank account';
    }
}
