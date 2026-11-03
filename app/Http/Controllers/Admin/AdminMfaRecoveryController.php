<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminMfaRecoveryController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['recovery_code' => ['required', 'regex:/\A[a-fA-F0-9]{32}\z/D']]);
        $id = $request->session()->get('admin_pending.user_id');
        $created = $request->session()->get('admin_pending.created_at');
        if (! is_numeric($id) || ! is_int($created) || $created > time() || time() - $created >= 300) {
            $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at', 'admin_pending.enrollment_secret']);
            abort(403, 'Administrator login challenge expired.');
        }
        $hash = hash('sha256', strtolower($data['recovery_code']));
        $user = User::query()->find($id);
        $admin = $user ? PlatformAdmin::query()->where('user_id', $user->id)->first() : null;
        if (! $user || ! $admin) {
            abort(403);
        }
        $verifiedVersion = DB::transaction(function () use ($user, $admin, $hash, $request): ?int {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $lockedAdmin = PlatformAdmin::query()->whereKey($admin->id)->lockForUpdate()->first();
            if (! $lockedUser || ! $lockedUser->hasPassword() || $lockedUser->status !== AccountStatus::Active
                || $lockedUser->email_verified_at === null || ! $lockedAdmin
                || $lockedAdmin->status !== AccountStatus::Active
                || (string) $lockedAdmin->user_id !== (string) $lockedUser->id
                || (int) $request->session()->get('admin_pending.session_version', 0) !== (int) $lockedAdmin->session_version
                || ! AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists()) {
                return null;
            }
            // Atomic deletion ensures that one code can authenticate at most once.
            return DB::table('platform_admin_mfa_recovery_codes')
                ->where('platform_admin_id', $admin->id)
                ->where('code_hash', $hash)
                ->delete() === 1 ? (int) $lockedAdmin->session_version : null;
        });
        if ($verifiedVersion === null) {
            $audit->record('admin.mfa_recovery_failed', $user);
            throw ValidationException::withMessages(['recovery_code' => 'Invalid or previously used recovery code.']);
        }
        $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at', 'admin_pending.enrollment_secret']);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('admin_mfa.user_id', $user->getAuthIdentifier());
        $request->session()->put('admin_mfa.last_activity', time());
        $request->session()->put('admin_mfa.session_version', $verifiedVersion);
        $audit->record('admin.mfa_recovery_used', $user);
        return redirect()->route('admin.dashboard');
    }
}
