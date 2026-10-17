<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Private records: only the user who created them can see them. There is NO role exception.
 * A business owner or administrator gets exactly the same empty result as any other member.
 * Fails closed: with no current user, a query returns nothing.
 */
final class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentBusiness::class);

        if ($current->isUnscoped()) {
            return;
        }

        $userId = $current->userId();

        if ($userId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('owner_user_id'), $userId);
    }
}
