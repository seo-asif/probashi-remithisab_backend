<?php

namespace App\Support\Records;

use App\Models\AuditLog;
use App\Support\Manager\ManagerOptions as O;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Money Rules 5: every change to a money record writes an audit_logs row (who, when, old values, new values).
 * Written in the same database transaction as the change, so there is never a change without its history.
 * Private Sender records are logged too, but only the owner's own app ever reads them.
 */
final class MoneyAudit
{
    private const SKIP = [
        'id',
        'business_id',
        'owner_user_id',
        'created_at',
        'updated_at',
    ];

    private const SENSITIVE = [
        'pay_to',
        'sending_number',
        'phone',
        'phone_normalized',
        'phone_key',
        // Do not duplicate personal names from private financial records
        // in broad audit metadata. The record UUID remains available.
        'name',
        'from_name',
        'recipient_name',
        'customer_name',
        'account_name',
        'owner_name',
        'beneficiary_name',
        'holder_name',
        'address',
        'notes',
        'note',
        'bank',
        'banks',
        'bkash_numbers',
        'nagad_numbers',
        'rocket_numbers',
    ];

    /** $action: created | updated | deleted | restored | payment_added | payment_reversed | payments_removed ... */
    public static function record(string $action, Model $record, array $old = [], array $new = [], array $extra = []): void
    {
        $current = app(CurrentBusiness::class);
        $request = request();

        $old = array_diff_key($old, array_flip(self::SKIP));
        $new = array_diff_key($new, array_flip(self::SKIP));

        if ($action === 'updated') {
            $changed = array_keys(array_filter($new, fn($v, $k) => self::norm($old[$k] ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH));
            if (!$changed) {
                return;
            }
            $old = array_intersect_key($old, array_flip($changed));
            $new = array_intersect_key($new, array_flip($changed));
        }

        AuditLog::create([
            'business_id'   => $record->business_id ?? $current->id(),
            'actor_user_id' => $current->userId() ?? $request?->user()?->id,
            'action'        => Str::snake(class_basename($record)) . '.' . $action,
            'target_type'   => class_basename($record),
            'target_id'     => $record->getKey(),
            'metadata'      => self::sanitizeMetadata(
                array_filter(
                    [
                         ...self::allowedExtraMetadata($action, $extra),
                        'id'  => $record->uuid ?? null,
                        'old' => $old ?: null,
                        'new' => $new ?: null,
                    ],
                    fn($v) => $v !== null
                )
            ),
            'ip'            => $request?->ip(),
            'user_agent'    => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
        ]);
    }

    /** The stored values of a model, ready for the log (dates as text). */
    public static function values(Model $record): array
    {
        $out = [];

        foreach ($record->getAttributes() as $key => $value) {
            $cast = $record->getAttribute($key);

            $out[$key] = $cast instanceof \DateTimeInterface
            ? $cast->format('Y-m-d\TH:i:s\Z')
            : $cast;
        }

        return $out;

    }

    private static function allowedExtraMetadata(string $action, array $extra): array
    {
        $allowed = [
            'payment',
            'reversal',
            'amount_bdt',
            'auto',
            'reason',
            'payments',
        ];

        $extra = array_intersect_key($extra, array_flip($allowed));

        foreach (['payment', 'reversal'] as $field) {
            if (array_key_exists($field, $extra)) {
                if (
                    !is_string($extra[$field]) ||
                    !Str::isUuid($extra[$field])
                ) {
                    throw new InvalidArgumentException(
                        "Invalid audit metadata: {$field}"
                    );
                }
            }
        }

        if (array_key_exists('amount_bdt', $extra)) {
            self::validateAuditAmount($extra['amount_bdt']);
        }

        if (
            array_key_exists('auto', $extra) &&
            !is_bool($extra['auto'])
        ) {
            throw new InvalidArgumentException(
                'Invalid audit metadata: auto'
            );
        }

        if (array_key_exists('reason', $extra)) {
            $reason = $extra['reason'];

            if (!is_string($reason)) {
                throw new InvalidArgumentException(
                    'Invalid audit metadata: reason'
                );
            }

            $statuses = array_map(
                static fn(string $status): string => preg_quote($status, '/'),
                O::STATUSES
            );

            $statusPattern = implode('|', $statuses);

            $validReason = preg_match(
                '/^status (?:' . $statusPattern . ') -> (?:' . $statusPattern . ')$/D',
                $reason
            ) === 1;

            if (!$validReason) {
                throw new InvalidArgumentException(
                    'Invalid audit metadata: reason'
                );
            }
        }

        if (array_key_exists('payments', $extra)) {
            if (!is_array($extra['payments']) || !array_is_list($extra['payments'])) {
                throw new InvalidArgumentException(
                    'Invalid audit metadata: payments'
                );
            }

            foreach ($extra['payments'] as $payment) {
                if (
                    !is_array($payment) ||
                    array_keys($payment) !== ['id', 'amount_bdt'] ||
                    !is_string($payment['id']) ||
                    !Str::isUuid($payment['id'])
                ) {
                    throw new InvalidArgumentException(
                        'Invalid audit metadata: payment entry'
                    );
                }

                self::validateAuditAmount($payment['amount_bdt']);
            }
        }

        self::validateActionMetadata($action, $extra);

        return $extra;
    }

    private static function validateActionMetadata(
        string $action,
        array $extra
    ): void {
        $required = match ($action) {
            'payment_added'    => ['amount_bdt'],
            'payment_reversed' => ['payment', 'reversal', 'amount_bdt'],
            'payments_removed' => ['reason', 'payments'],
            default            => [],
        };

        $allowed = match ($action) {
            'payment_added'    => ['payment', 'amount_bdt', 'auto'],
            'payment_reversed' => ['payment', 'reversal', 'amount_bdt'],
            'payments_removed' => ['reason', 'payments'],
            default            => [],
        };

        foreach ($required as $field) {
            if (!array_key_exists($field, $extra)) {
                throw new InvalidArgumentException(
                    "Missing audit metadata: {$field}"
                );
            }
        }

        foreach (array_keys($extra) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Unexpected audit metadata for {$action}: {$field}"
                );
            }
        }

        if ($action === 'payment_added') {
            $manual = isset($extra['payment']) && !isset($extra['auto']);
            $automatic = ($extra['auto'] ?? null) === true
            && !isset($extra['payment']);

            if (!$manual && !$automatic) {
                throw new InvalidArgumentException(
                    'Invalid payment_added audit metadata'
                );
            }

            if (bccomp($extra['amount_bdt'], '0', 2) <= 0) {
                throw new InvalidArgumentException(
                    'Payment amount must be positive'
                );
            }
        }

        if ($action === 'payment_reversed') {
            if (bccomp($extra['amount_bdt'], '0', 2) >= 0) {
                throw new InvalidArgumentException(
                    'Reversal amount must be negative'
                );
            }
        }

        if ($action === 'payments_removed' && count($extra['payments']) === 0) {
            throw new InvalidArgumentException(
                'Removed payments list cannot be empty'
            );
        }
    }

    private static function validateAuditAmount(mixed $amount): void
    {
        if (
            !is_string($amount) ||
            preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid audit metadata: amount_bdt'
            );
        }
    }

    private static function sanitizeMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::SENSITIVE, true)) {
                unset($metadata[$key]);
                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = self::sanitizeMetadata($value);
            }
        }

        return $metadata;
    }

    private static function norm(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        if (is_array($v)) {
            return json_encode($v);
        }

        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }

        $s = (string) $v;

        // Normalize ordinary decimal strings without float conversion.
        // Preserve all significant digits.
        if (preg_match('/\A([+-]?)([0-9]+)(?:\.([0-9]+))?\z/D', $s, $matches) !== 1) {
            return $s;
        }

        $sign = $matches[1];
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;

        $fraction = rtrim($matches[3] ?? '', '0');

        // All representations of zero should compare equally.
        if ($integer === '0' && $fraction === '') {
            return '0';
        }

        $normalized = $integer;

        if ($fraction !== '') {
            $normalized .= '.' . $fraction;
        }

        return $sign === '-' ? '-' . $normalized : $normalized;
    }

}
