<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Fails closed: with no current business, a query returns nothing instead of everything. */
final class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentBusiness::class);

        if ($current->isUnscoped()) {
            return;
        }

        $id = $current->id();

        if ($id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('business_id'), $id);
    }
}
