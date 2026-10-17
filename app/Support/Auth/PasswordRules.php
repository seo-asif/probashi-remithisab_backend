<?php

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** At least 8 characters with letters and numbers. */
    public static function default(): Password
    {
        return Password::min(8)->letters()->numbers();
    }
}
