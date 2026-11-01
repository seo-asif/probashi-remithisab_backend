<?php

namespace App\Support\Auth;

final class DisposableEmailService
{
    public static function isDisposable(string $email): bool
    {
        $domain = strtolower(trim(
            substr(strrchr($email, '@') ?: '', 1)
        ));

        if ($domain === '') {
            return false;
        }

        $domains = config('disposable_email.domains', []);

        if (!is_array($domains)) {
            return false;
        }

        foreach ($domains as $blocked) {
            if (!is_string($blocked)) {
                continue;
            }

            $blocked = strtolower(trim($blocked));

            if ($blocked === '') {
                continue;
            }

            if (
                $domain === $blocked
                || str_ends_with($domain, '.' . $blocked)
            ) {
                return true;
            }
        }

        return false;
    }
}
