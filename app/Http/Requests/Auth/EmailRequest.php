<?php

namespace App\Http\Requests\Auth;

class EmailRequest extends AuthRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255']];
    }
}
