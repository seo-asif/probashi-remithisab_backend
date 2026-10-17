<?php

namespace App\Support\Records;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Money Rules 5: every change to a money record writes an audit_logs row (who, when, old values, new values).
 * Written in the same database transaction as the change, so there is never a change without its history.
 * Private Sender records are logged too, but only the owner's own app ever reads them.
 */
final class MoneyAudit
{
    private const SKIP = ['id', 'business_id', 'owner_user_id', 'created_at', 'updated_at'];

    /** $action: created | updated | deleted | restored | payment_added | payment_reversed | payments_removed ... */
    public static function record(string $action, Model $record, array $old = [], array $new = [], array $extra = []): void
    {
        $current = app(CurrentBusiness::class);
        $request = request();

        $old = array_diff_key($old, array_flip(self::SKIP));
        $new = array_diff_key($new, array_flip(self::SKIP));

        if ($action === 'updated') {
            $changed = array_keys(array_filter($new, fn ($v, $k) => self::norm($old[$k] ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH));
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
            'metadata'      => array_filter(['id' => $record->uuid ?? null, 'old' => $old ?: null, 'new' => $new ?: null, ...$extra], fn ($v) => $v !== null),
            'ip'            => $request?->ip(),
            'user_agent'    => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
        ]);
    }

    /** The stored values of a model, ready for the log (dates as text). */
    public static function values(Model $record): array
    {
        $out = [];
        foreach ($record->getAttributes() as $key => $value) {
            $cast  = $record->getAttribute($key);
            $out[$key] = $cast instanceof \DateTimeInterface ? $cast->format('Y-m-d\TH:i:s\Z') : $cast;
        }

        return $out;
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

        return is_numeric($s) ? rtrim(rtrim(number_format((float) $s, 8, '.', ''), '0'), '.') : $s;
    }
}
