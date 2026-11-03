<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Services\Auth\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AdminSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $admin = PlatformAdmin::query()->where('user_id', $user->id)->firstOrFail();

        return view('admin.settings', [
            'account' => $user,
            'mfaEnabled' => AdminMfaCredential::query()
                ->where('platform_admin_id', $admin->id)->exists(),
            'recoveryCodesRemaining' => \Illuminate\Support\Facades\DB::table('platform_admin_mfa_recovery_codes')
                ->where('platform_admin_id', $admin->id)->count(),
        ]);
    }

    public function changePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()],
        ]);

        $user = $request->user();
        if (! $user->hasPassword() || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from your current password.',
            ]);
        }

        // The User password cast hashes the new password before storage.
        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $audit->record('admin.password_changed', $user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Password updated. Sign in again with your new password.');
    }
}
