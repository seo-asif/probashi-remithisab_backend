<?php

namespace App\Support\Records;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Models\StudentMoneyIn;
use Illuminate\Support\Facades\DB;

/**
 * Only a newly created, unused profile may be permanently deleted.
 * All linked records, including soft-deleted history, prevent deletion.
 * This is the sole way to reclaim a lifetime-created Free-plan slot.
 */
final class ProfileDeletion
{
    public static function permanent(Customer|SenderRecipient $profile): void
    {
        DB::transaction(function () use ($profile): void {
            // Serialize against other profile creation in the same business.
            \App\Models\Business::query()->whereKey($profile->business_id)->lockForUpdate()->firstOrFail();
            $locked = $profile::withTrashed()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->created_at === null
                || now()->lessThan($locked->created_at)
                || now()->greaterThan($locked->created_at->copy()->addMinutes(10))) {
                throw new ApiException(
                    'PROFILE_DELETE_WINDOW_EXPIRED',
                    'Only a profile created in the last 10 minutes can be permanently deleted.',
                    409,
                );
            }

            if ($locked instanceof Customer) {
                $hasHistory = ManagerTransaction::withoutGlobalScopes()->withTrashed()
                    ->where('customer_id', $locked->id)->exists();
            } else {
                $hasHistory = SenderRemittance::withoutGlobalScopes()->withTrashed()
                    ->where('recipient_id', $locked->id)->exists()
                    || StudentMoneyIn::withoutGlobalScopes()->withTrashed()
                        ->where('from_recipient_id', $locked->id)->exists();
            }

            if ($hasHistory) {
                throw new ApiException(
                    'PROFILE_HAS_RECORDS',
                    'This profile has records and cannot be permanently deleted. Archive it instead.',
                    409,
                );
            }

            $locked->forceDelete();
        });
    }
}
