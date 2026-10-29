<?php

namespace App\Http\Middleware;

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared Manager-mode mutations are available to owners, administrators, and operators.
 * Read-only members may view shared Manager data but cannot change it.
 * Runs after business.context so the membership role is derived from the authenticated user.
 */
final class RequireManagerWriteAccess
{
    public function __construct(private CurrentBusiness $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $role = $this->current->membership()?->role;

        if (!in_array($role, [
            BusinessRole::Owner,
            BusinessRole::Administrator,
            BusinessRole::Operator,
        ], true)) {
            throw new ApiException(
                'FORBIDDEN',
                'Your business role does not allow changes to Manager records.',
                403,
            );
        }

        return $next($request);
    }
}
