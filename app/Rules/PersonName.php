<?php

namespace App\Rules;

use App\Support\Sender\SenderOptions;
use App\Support\Validation\Text;
use App\Support\Validation\Reject;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Family member name: 2 to 20 characters (see SenderOptions), letters, spaces, . - and ' only, no code. */
class PersonName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = is_string($value) ? Text::personNameError($value, SenderOptions::NAME_MIN, SenderOptions::NAME_MAX) : 'Family member name is required.';

        if ($error !== null) {
            $code = match (true) {
                str_contains($error, 'required') => 'name_required',
                str_contains($error, 'characters') => 'name_length',
                default => 'name_invalid',
            };
            $fail(Reject::msg($code, $error));
        }
    }
}
