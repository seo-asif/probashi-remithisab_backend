<?php

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Turns "a function that returns [code, English] or null" into a validation rule with a coded message. */
final class Coded implements ValidationRule
{
    /** @param callable(mixed): (array{0: string, 1: string}|null) $check */
    public function __construct(private $check)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (($error = ($this->check)($value)) !== null) {
            $fail(Reject::msg($error[0], $error[1]));
        }
    }
}
