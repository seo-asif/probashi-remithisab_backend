<?php

namespace App\Support\Validation;

/** Pure text checks that match the app (v1.1.240): grapheme length, simple plain text, person names. */
final class Text
{
    /** Length as a person counts it: "মা" or an emoji with modifiers is 1 character each. */
    public static function graphemeLength(string $text): int
    {
        $count = preg_match_all('/\X/u', $text);

        return $count === false ? strlen($text) : $count;
    }

    /** The app's plainCode(): rejects HTML tags, script links, code fences and template markers. */
    public static function containsCode(string $text): bool
    {
        return (bool) preg_match('/<\/?[a-z][^>]*>|javascript\s*:|`{1,3}|\$\{|<%|%>|\b(?:eval|exec|import|require)\s*\(/i', $text);
    }

    /** @return string|null the error message, or null when the name is acceptable */
    public static function personNameError(string $name, int $min = 1, int $max = 20): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return 'Family member name is required.';
        }

        $length = self::graphemeLength($name);

        if ($length < $min || $length > $max) {
            return "Name must contain {$min}–{$max} characters.";
        }

        if (self::containsCode($name) || !preg_match("/^[\\p{L}\\p{M} .\\-']+$/u", $name)) {
            return "Use letters, spaces, . - and ' only.";
        }

        return null;
    }
}
