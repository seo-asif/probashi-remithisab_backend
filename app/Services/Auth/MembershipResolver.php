<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Models\BusinessMembership;
use App\Models\User;

/** The single place that decides "which business is this user in, and may they use it?". */
class MembershipResolver
{
    public function forUser(User $user): BusinessMembership
    {
        if ($user->status !== AccountStatus::Active) {
            throw new ApiException('ACCOUNT_SUSPENDED', 'This account is suspended. Please contact support.', 403);
        }

        if (!$user->hasVerifiedEmail()) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 'Please verify your email address to continue.', 403, ['email' => $user->email]);
        }

        $memberships = $user->memberships()
            ->where('status', AccountStatus::Active->value)
            ->with('business')
            ->get()
            ->filter(fn ($membership) => $membership->business !== null);

        if ($memberships->isEmpty()) {
            throw new ApiException('NO_BUSINESS', 'This account is not connected to a business.', 403);
        }

        if ($memberships->count() > 1) {
            throw new ApiException('BUSINESS_SELECTION_REQUIRED', 'This account belongs to more than one business. Choosing a business is not supported yet.', 409);
        }

        $membership = $memberships->first();

        if ($membership->business->status !== AccountStatus::Active) {
            throw new ApiException('BUSINESS_SUSPENDED', 'This business is suspended. Please contact support.', 403);
        }

        return $membership;
    }
}
