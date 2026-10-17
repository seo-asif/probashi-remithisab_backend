<?php

namespace App\Support\Usage;

/**
 * Which edits use the shared monthly edit allowance (decision: only transactions and remittances).
 * One allowance per business, shared by Operator and Sender Mode.
 */
final class EditPolicy
{
    /** A successful edit with a real change of one of these uses one edit. */
    public const COUNTED = ['operator_transaction', 'operator_remittance', 'sender_remittance'];

    /** Never use the allowance. */
    public const NOT_COUNTED = ['customer', 'family_member', 'payment_account', 'settings'];

    /** Bookkeeping fields: a change to these alone is not an edit. */
    public const IGNORED_FIELDS = ['id', 'uuid', 'client_uuid', 'base_updated_at', 'created_at', 'updated_at', 'deleted_at'];

    public static function isCounted(string $recordType): bool
    {
        return in_array($recordType, self::COUNTED, true);
    }

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
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
