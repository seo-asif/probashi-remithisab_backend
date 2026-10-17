<?php

namespace App\Support\Api;

use App\Exceptions\ApiException;
use App\Support\Time\BusinessClock;
use Illuminate\Database\Eloquent\Model;

/**
 * Offline edits: the app sends base_updated_at = the updated_at it last saw. If the record changed on the
 * server since then (another phone edited it), the edit is refused with EDIT_CONFLICT and the current
 * record, instead of silently overwriting the other change. Without base_updated_at the edit goes through.
 */
final class EditGuard
{
    public static function assertFresh(Model $model, mixed $baseUpdatedAt, callable $present): void
    {
        if ($baseUpdatedAt === null || $baseUpdatedAt === '') {
            return;
        }

        try {
            $base = new \DateTimeImmutable((string) $baseUpdatedAt);
        } catch (\Throwable) {
            throw new ApiException('VALIDATION_FAILED', 'Please correct the highlighted fields.', 422, null, [
                'base_updated_at' => ['Send the updated_at value you received from the server.'],
            ]);
        }

        if ($model->updated_at && $model->updated_at->getTimestamp() !== $base->getTimestamp()) {
            throw new ApiException('EDIT_CONFLICT', 'This record was changed on another device. Review the latest version and try again.', 409, [
                'current'          => $present($model),
                'server_updated_at'=> BusinessClock::utcIso($model->updated_at),
            ]);
        }
    }
}
