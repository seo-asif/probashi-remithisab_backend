<?php

namespace App\Rules;

use App\Support\Validation\Text;
use App\Support\Validation\Reject;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Notes must be simple text: no HTML tags, script links, code fences or template markers. */
class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Text::containsCode($value)) {
            $fail(Reject::msg('not_plain_text', 'Use simple plain text only.'));
        }
    }
}
