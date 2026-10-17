<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Put this on every model that has a business_id column.
 *  - every query is limited to the current business (and returns nothing if there is none)
 *  - business_id is filled in automatically on create, and a different business_id is refused
 *  - business_id can never be changed afterwards
 *
 * Rule: do NOT use implicit route-model binding for these models. Look the record up inside the
 * controller (Model::findOrFail($id)) after the business context middleware has run.
 */
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope());

        static::creating(function (Model $model) {
            $current = app(CurrentBusiness::class);

            if ($current->isUnscoped()) {
                if (empty($model->business_id)) {
                    throw new \LogicException('business_id is required when creating ' . class_basename($model) . ' outside a business.');
                }

                return;
            }

            $id = $current->id();

            if ($id === null) {
                throw new \LogicException('No current business: cannot create ' . class_basename($model) . '.');
            }

            if (!empty($model->business_id) && (int) $model->business_id !== $id) {
                throw new \LogicException('Writing to another business is not allowed.');
            }

            $model->business_id = $id;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('business_id')) {
                throw new \LogicException('business_id cannot be changed.');
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
