<?php

namespace App\Http\Middleware;

use App\Services\Auth\MembershipResolver;
use App\Support\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: business.context. Runs after auth:sanctum.
 * The business comes ONLY from the user's own membership. Nothing in the request is trusted.
 * The rules (active account, verified email, one active business) live in MembershipResolver.
 */
class ResolveCurrentBusiness
{
    public function __construct(private CurrentBusiness $current, private MembershipResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            throw new AuthenticationException();
        }

        $membership = $this->resolver->forUser($user);

        $this->current->set($membership->business, $membership);

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }
}
