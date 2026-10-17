<?php

namespace App\Support\Validation;

use Illuminate\Validation\ValidationException;

/**
 * Every rejected field gets a fixed CODE (for the Flutter app, which shows its own Bangla text) and an English
 * message. Inside Laravel a coded message is written as "⟦code⟧English text"; the error renderer splits it into
 * "errors" (English) and "error_codes" (codes). Messages without a code get one from the rule that failed.
 */
final class Reject
{
    private const PATTERN = '/^⟦([a-z0-9_]+)⟧(.*)$/su';

    public static function msg(string $code, string $text): string
    {
        return '⟦' . $code . '⟧' . $text;
    }

    /** @return array{0: ?string, 1: string} [code or null, English text] */
    public static function split(string $message): array
    {
        return preg_match(self::PATTERN, $message, $m) ? [$m[1], $m[2]] : [null, $message];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $errors field => [code, English]
     */
    public static function fail(array $errors): never
    {
        throw ValidationException::withMessages(array_map(fn (array $e) => self::msg($e[0], $e[1]), $errors));
    }

    /** Code used when a plain Laravel rule failed without a coded message. */
    public static function codeForRule(?string $rule): string
    {
        return match ($rule) {
            'Required', 'RequiredIf', 'RequiredWith', 'Present' => 'required',
            'Max', 'Lte', 'Lt'                                  => 'too_large',
            'Min', 'Gte'                                        => 'too_small',
            'Gt'                                                => 'must_be_positive',
            'Regex', 'String', 'Array', 'Json'                  => 'invalid_format',
            'In', 'Boolean', 'Enum'                             => 'invalid_choice',
            'Uuid'                                              => 'invalid_id',
            'Numeric', 'Integer', 'Decimal'                     => 'not_a_number',
            'Distinct', 'Unique'                                => 'duplicate',
            'Exists'                                            => 'not_found',
            'Date', 'DateFormat', 'Before', 'After', 'BeforeOrEqual', 'AfterOrEqual' => 'invalid_date',
            'Email'                                             => 'invalid_email',
            'Confirmed'                                         => 'not_confirmed',
            'ProhibitedIf', 'Prohibited'                        => 'not_allowed',
            default                                             => 'invalid',
        };
    }
}
