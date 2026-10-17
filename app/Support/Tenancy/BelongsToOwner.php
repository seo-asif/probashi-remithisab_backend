<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For records that are private to the user who created them (Sender family members and remittances).
 * Needs business_id AND owner_user_id columns. Includes everything BelongsToBusiness does, plus:
 *  - every query is limited to the current user, with no role override (owners and admins included)
 *  - owner_user_id is filled in on create, and a different owner_user_id is refused
 *  - owner_user_id can never be changed afterwards
 * Handing a record to another user is a separate, explicitly approved feature. It is not built here.
 */
trait BelongsToOwner
{
    use BelongsToBusiness;

    protected static function bootBelongsToOwner(): void
    {
        static::addGlobalScope(new OwnerScope());

        static::creating(function (Model $model) {
            $current = app(CurrentBusiness::class);

            if ($current->isUnscoped()) {
                if (empty($model->owner_user_id)) {
                    throw new \LogicException('owner_user_id is required when creating ' . class_basename($model) . ' outside a user context.');
                }

                return;
            }

            $userId = $current->userId();

            if ($userId === null) {
                throw new \LogicException('No current user: cannot create private ' . class_basename($model) . '.');
            }

            if (!empty($model->owner_user_id) && (int) $model->owner_user_id !== $userId) {
                throw new \LogicException('Creating a record for another user is not allowed.');
            }

            $model->owner_user_id = $userId;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('owner_user_id')) {
                throw new \LogicException('owner_user_id cannot be changed.');
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
