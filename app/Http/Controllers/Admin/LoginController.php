<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Contracts\View\View;

final class LoginController extends Controller
{
    public function show(Request $request): View
    {
        return view('admin.login');
    }

    public function submit(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($data['email']));
        // Account-level limit is shared across clients, regardless of IP address.
        // Hash the normalized address so cache keys never contain raw emails.
        $accountKey = 'admin-login:account:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($accountKey, 5)) {
            abort(429, 'Too many attempts. Try again later.');
        }
        $user = User::query()->where('email', $email)->first();
        $hash = $user?->hasPassword() ? $user->password : Hash::make(Str::random(32));
        $passwordValid = Hash::check($data['password'], $hash);
        $eligible = $passwordValid
            && $user !== null
            && $user->hasPassword()
            && $user->status === AccountStatus::Active
            && $user->email_verified_at !== null
            && PlatformAdmin::query()
                ->where('user_id', $user->id)
                ->where('status', AccountStatus::Active->value)
                ->exists();

        // Clear any existing browser authentication, including an earlier MFA grant.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $eligible) {
            RateLimiter::hit($accountKey, 900);
            $audit->record('admin.login_failed', $user);
            return back()->withErrors(['email' => 'Invalid administrator credentials.']);
        }

        // This is a restricted pre-authentication session, never Auth::login().
        $request->session()->put('admin_pending.user_id', $user->getAuthIdentifier());
        $request->session()->put('admin_pending.created_at', time());
        $audit->record('admin.password_verified', $user);
        return redirect()->route('admin.mfa.pending');
    }

    public function pending(Request $request): View|RedirectResponse
    {
        $created = $request->session()->get('admin_pending.created_at');
        $id = $request->session()->get('admin_pending.user_id');
        if (! is_int($created) || $created > time() || time() - $created >= 300 || ! is_numeric($id)) {
            $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at']);
            return redirect()->route('admin.login');
        }
        // The challenge is intentionally unavailable until TOTP is implemented.
        return view('admin.mfa-pending');
    }
}