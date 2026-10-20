<?php

namespace App\Support\Records;

use App\Support\Validation\Reject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Money Rules 5: the PHONE makes every record's id (UUID v7), also offline, and the server keeps it.
 * Sending the same record again never makes a second one: the id is the idempotency key.
 * If the phone sends no id, the server makes a UUID v7.
 */
final class RecordId
{
    /**
     * Returns the record already saved with this id in the current scope (also if deleted), or null.
     * An id already used by someone else's record is refused, without saying whose it is.
     *
     * @param class-string<Model> $model
     */
    public static function existing(string $model, mixed $id): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (!is_string($id) || !Str::isUuid($id)) {
            Reject::fail(['id' => ['invalid_id', 'The record id must be a UUID made by the app.']]);
        }

        $mine = $model::query()->withTrashed()->where('uuid', $id)->first();

        if ($mine) {
            return $mine;
        }

        if ($model::withoutGlobalScopes()->where('uuid', $id)->exists()) {
            Reject::fail(['id' => ['id_taken', 'This record id is already used. Make a new id and try again.']]);
        }

        return null;
    }

    public static function make(mixed $id): string
    {
        return is_string($id) && Str::isUuid($id) ? strtolower($id) : (string) Str::uuid7();
    }
}
