<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\PasswordRules;
use App\Support\Sender\SenderValidation;

class RegisterRequest extends AuthRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merge = [];
        foreach (['name', 'business_name'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }
        if (is_string($this->input('country_code'))) {
            $merge['country_code'] = strtoupper(trim($this->input('country_code')));
        }
        if ($this->has('business_phone')) {
            $merge['business_phone'] = SenderValidation::cleanPhone($this->input('business_phone'));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:120'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'confirmed', PasswordRules::default()],
            'business_name'  => ['required', 'string', 'max:120'],
            'business_phone' => ['nullable', 'string', 'regex:/^\+?\d{1,18}$/'],
            'country_code'   => ['required', 'alpha', 'size:2'],
            'timezone'       => ['required', 'timezone'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'         => 'This email is already registered. Try logging in, or ask for a new verification code.',
            'business_phone.regex' => 'Enter a valid phone number (digits only, an optional + at the start).',
            'timezone.timezone'    => 'Choose a valid timezone.',
        ];
    }
}
