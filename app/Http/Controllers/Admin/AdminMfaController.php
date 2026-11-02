<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\Admin\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminMfaController extends Controller
{
    private function pending(Request $request): array
    {
        $id = $request->session()->get('admin_pending.user_id');
        $created = $request->session()->get('admin_pending.created_at');
        if (! is_numeric($id) || ! is_int($created) || $created > time() || time() - $created >= 300) {
            $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at', 'admin_pending.enrollment_secret']);
            abort(403, 'Administrator login challenge expired.');
        }
        $user = User::query()->find($id);
        $admin = $user ? PlatformAdmin::query()->where('user_id', $user->id)->first() : null;
        if (! $user || $user->status !== AccountStatus::Active || $user->email_verified_at === null
            || ! $admin || $admin->status !== AccountStatus::Active) {
            $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at', 'admin_pending.enrollment_secret']);
            abort(403, 'Administrator access unavailable.');
        }
        return [$user, $admin];
    }

    public function show(Request $request): View|RedirectResponse
    {
        $id = $request->session()->get('admin_pending.user_id');
        $created = $request->session()->get('admin_pending.created_at');

        if (
            ! is_numeric($id)
            || ! is_int($created)
            || $created > time()
            || time() - $created >= 300
        ) {
            $request->session()->forget([
                'admin_pending.user_id',
                'admin_pending.created_at',
                'admin_pending.enrollment_secret',
            ]);

            return redirect()->route('admin.login');
        }

        [$user, $admin] = $this->pending($request);
        $credential = AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists();
        if ($credential) {
            $request->session()->forget('admin_pending.enrollment_secret');
            return view('admin.mfa-verify');
        }
        // Enrollment is never offered to a password-only browser session.
        abort(403, 'Administrator MFA must be provisioned by an authorized operator.');
    }

    public function submit(Request $request, AuditLogger $audit): RedirectResponse
    {
        [$user, $admin] = $this->pending($request);
        $data = $request->validate(['code' => ['required', 'regex:/\A[0-9]{6}\z/D']]);
        $success = DB::transaction(function () use ($admin, $data): bool {
            // Serialise verification so that the same TOTP step cannot be accepted twice.
            // Lock and revalidate eligibility at verification time.
            $lockedUser = User::query()->whereKey($admin->user_id)->lockForUpdate()->first();
            $locked = PlatformAdmin::query()->whereKey($admin->id)->lockForUpdate()->first();
            if (! $lockedUser || $lockedUser->status !== AccountStatus::Active
                || $lockedUser->email_verified_at === null
                || ! $locked || $locked->status !== AccountStatus::Active
                || (string) $locked->user_id !== (string) $lockedUser->id) {
                return false;
            }
            $credential = AdminMfaCredential::query()
                ->where('platform_admin_id', $admin->id)->lockForUpdate()->first();
            if (! $credential) {
                return false; // First-time enrollment is CLI-only.
            }
            $step = Totp::matchingStep($credential->secret, $data['code'], time(), $credential->last_used_step);
            if ($step === null) {
                return false;
            }
            $credential->last_used_step = $step;
            $credential->save();
            return true;
        });

        if (! $success) {
            $audit->record('admin.mfa_failed', $user);
            throw ValidationException::withMessages(['code' => 'Invalid or previously used authenticator code.']);
        }

        $request->session()->forget(['admin_pending.user_id', 'admin_pending.created_at', 'admin_pending.enrollment_secret']);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('admin_mfa.user_id', $user->getAuthIdentifier());
        $request->session()->put('admin_mfa.last_activity', time());
        $audit->record('admin.mfa_verified', $user);
        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        $audit->record('admin.logout', $user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
