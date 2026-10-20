<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * The record id ("id" in the API) is made by the phone (UUID v7); the numeric id is never shown.
 * Records are always looked up by uuid inside the current business (or owner) scope.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** Finds a record by its public id inside the current scope, or throws the standard 404. */
    public static function findByUuidOrFail(mixed $uuid, bool $withTrashed = false): static
    {
        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(static::class);
        }

        $query = static::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->where('uuid', $uuid)->firstOrFail();
    }
}
