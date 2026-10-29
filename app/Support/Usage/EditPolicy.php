<?php

namespace App\Support\Usage;

/**
 * Detect meaningful changes for financial validation and audit logging.
 * Free and Unlimited plans permit ordinary editing without an edit quota.
 */
final class EditPolicy
{
    /** Bookkeeping fields: a change to these alone is not an edit. */
    public const IGNORED_FIELDS = ['id', 'uuid', 'client_uuid', 'base_updated_at', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * True only if at least one submitted field really differs from the stored value.
     * "72.5" and "72.5000" are the same number, and "" and null are the same empty value.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $incoming
     * @param array<string, int>   $decimalScales  field => decimal places, for numeric fields
     */
    public static function hasRealChange(array $stored, array $incoming, array $decimalScales = []): bool
    {
        foreach ($incoming as $field => $value) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $old = $stored[$field] ?? null;

            if (isset($decimalScales[$field]) && is_numeric($old) && is_numeric($value)) {
                $scale = $decimalScales[$field];

                if (bccomp(number_format((float) $old, $scale, '.', ''), number_format((float) $value, $scale, '.', ''), $scale) !== 0) {
                    return true;
                }

                continue;
            }

            if (self::normalize($old) !== self::normalize($value)) {
                return true;
            }
        }

        return false;
    }

   private static function normalize(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        $value = self::normalizeArray($value);

        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $json === false ? serialize($value) : $json;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    $text = trim((string) $value);

    if ($text === '') {
        return null;
    }

    // Compare equivalent decimal representations as the same value:
    // 72.5 = 72.5000, 001.00 = 1, -0.00 = 0.
    if (
        preg_match(
            '/\A([+-]?)([0-9]+)(?:\.([0-9]+))?\z/D',
            $text,
            $matches,
        ) === 1
    ) {
        $sign = $matches[1];

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;

        $fraction = rtrim($matches[3] ?? '', '0');

        if ($integer === '0' && $fraction === '') {
            return '0';
        }

        $normalized = $integer;

        if ($fraction !== '') {
            $normalized .= '.' . $fraction;
        }

        return $sign === '-'
            ? '-' . $normalized
            : $normalized;
    }

    return $text;
}

private static function normalizeArray(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = self::normalizeArray($item);
        }
    }

    if (!array_is_list($value)) {
        ksort($value);
    }

    return $value;
}
}
