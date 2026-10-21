<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformAdmin
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException();
        }

        /*
         * Platform administration is independent from tenant roles.
         *
         * Business Owner / Administrator membership does NOT grant
         * platform administration access.
         */
        if (
            $user->status !== AccountStatus::Active
            || $user->email_verified_at === null
        ) {
            throw new ApiException(
                'PLATFORM_ADMIN_FORBIDDEN',
                'Platform administrator access is required.',
                403,
            );
        }

        $platformAdmin = PlatformAdmin::query()
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::Active->value)
            ->first();

        if ($platformAdmin === null) {
            throw new ApiException(
                'PLATFORM_ADMIN_FORBIDDEN',
                'Platform administrator access is required.',
                403,
            );
        }

        /*
         * Controllers can retrieve the exact authorized admin with:
         *
         * $request->attributes->get('platform_admin')
         *
         * PaymentApprovalService independently revalidates the admin
         * under a database lock before applying an entitlement.
         */
        $request->attributes->set(
            'platform_admin',
            $platformAdmin,
        );

        return $next($request);
    }
}
