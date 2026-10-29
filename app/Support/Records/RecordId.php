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

    /**
     * Atomically check the client UUID and execute the complete creation.
     *
     * Locking the business row serializes creation attempts in this tenant:
     * no two requests can both pass the UUID check and then perform quota
     * consumption, ledger insertion, or audit writes independently.
     * The callback MUST return [record, true] from within this transaction.
     *
     * @template T of Model
     * @param class-string<T> $model
     * @param callable(): array{0:T, 1:bool} $create
     * @param (callable(T): bool)|null $acceptExisting
     * @return array{0:T, 1:bool}
     */
    public static function withinCreationLock(
        string $model,
        mixed $id,
        callable $create,
        ?callable $acceptExisting = null,
    ): array {
        $businessId = app(\App\Support\Tenancy\CurrentBusiness::class)->id();

        if ($businessId === null) {
            throw new \LogicException('Creating financial records requires a business context.');
        }

        return \Illuminate\Support\Facades\DB::transaction(
            function () use ($model, $id, $create, $acceptExisting, $businessId): array {
                \App\Models\Business::query()
                    ->whereKey($businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = self::existing($model, $id);
                if ($existing !== null) {
                    if ($acceptExisting !== null && !$acceptExisting($existing)) {
                        Reject::fail(['id' => [
                            'id_taken',
                            'This record id already belongs to a different record.',
                        ]]);
                    }

                    return [$existing, false];
                }

                return $create();
            },
            3,
        );
    }

    public static function make(mixed $id): string
    {
        return is_string($id) && Str::isUuid($id) ? strtolower($id) : (string) Str::uuid7();
    }
}
