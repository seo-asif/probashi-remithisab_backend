<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed until a future verified MFA challenge establishes session state.
 * Never set these session keys from a login form or client-supplied data.
 */
final class RequireAdminMfa
{
    private const IDLE_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $verifiedUserId = $request->session()->get('admin_mfa.user_id');
        $lastActivity = $request->session()->get('admin_mfa.last_activity');
        $admin = $request->attributes->get('platform_admin');
        // Missing stamps are version zero for pre-migration sessions only.
        $sessionVersion = $request->session()->get('admin_mfa.session_version', 0);

        $allowed = $user !== null
            && is_numeric($verifiedUserId)
            && (string) $verifiedUserId === (string) $user->getAuthIdentifier()
            && is_int($lastActivity)
            && $lastActivity <= time()
            && (time() - $lastActivity) < self::IDLE_SECONDS
            && $admin instanceof \App\Models\PlatformAdmin
            && is_int($sessionVersion)
            && $sessionVersion === (int) $admin->session_version;

        if (! $allowed) {
            // Clear both the MFA grant and the web authentication session.
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Administrator MFA verification is required.');
        }

        $request->session()->put('admin_mfa.last_activity', time());
        return $next($request);
    }
}