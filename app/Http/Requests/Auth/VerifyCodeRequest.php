<?php

namespace App\Http\Requests\Auth;

class VerifyCodeRequest extends AuthRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code'  => ['required', 'digits:' . (int) config('remit.otp.length')],
        ];
    }

    public function messages(): array
    {
        return ['code.digits' => 'Enter the :digits digit code from the email.'];
    }
}
