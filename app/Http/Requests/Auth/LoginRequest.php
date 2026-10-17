<?php

namespace App\Http\Requests\Auth;

class LoginRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
