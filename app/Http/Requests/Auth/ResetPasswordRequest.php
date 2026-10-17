<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\PasswordRules;

class ResetPasswordRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'email'       => ['required', 'email', 'max:255'],
            'reset_token' => ['required', 'string', 'max:100'],
            'password'    => ['required', 'confirmed', PasswordRules::default()],
        ];
    }
}
