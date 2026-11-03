<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\Admin\Totp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AdminRecoveryCodesController extends Controller
{
    public function regenerate(Request $request, AuditLogger $audit): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'mfa_code' => ['required', 'regex:/\A[0-9]{6}\z/D'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $rateKey = 'admin-recovery-regenerate:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            abort(429, 'Too many verification attempts. Try again later.');
        }

        // Plaintext codes exist only for this request and its immediate HTML response.
        $codes = array_map(static fn (): string => bin2hex(random_bytes(16)), range(1, 8));

        $success = DB::transaction(function () use ($user, $data, $codes): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $admin = PlatformAdmin::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            if (! $lockedUser || ! $lockedUser->hasPassword()
                || $lockedUser->status !== AccountStatus::Active || $lockedUser->email_verified_at === null
                || ! $admin || $admin->status !== AccountStatus::Active
                || ! Hash::check($data['current_password'], $lockedUser->password)) {
                return false;
            }

            $credential = AdminMfaCredential::query()
                ->where('platform_admin_id', $admin->id)->lockForUpdate()->first();
            if (! $credential) {
                return false;
            }
            $step = Totp::matchingStep(
                $credential->secret,
                $data['mfa_code'],
                time(),
                $credential->last_used_step,
            );
            if ($step === null) {
                return false;
            }

            $now = now();
            DB::table('platform_admin_mfa_recovery_codes')
                ->where('platform_admin_id', $admin->id)->delete();
            DB::table('platform_admin_mfa_recovery_codes')->insert(
                array_map(static fn (string $code): array => [
                    'platform_admin_id' => $admin->id,
                    'code_hash' => hash('sha256', $code),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $codes),
            );
            $credential->last_used_step = $step;
            $credential->save();
            return true;
        });

        if (! $success) {
            RateLimiter::hit($rateKey, 900);
            throw ValidationException::withMessages([
                'mfa_code' => 'The password or authenticator code is incorrect or already used.',
            ]);
        }

        RateLimiter::clear($rateKey);
        $audit->record('admin.mfa_recovery_regenerated', $user);

        return response()->view('admin.recovery-codes', ['codes' => $codes])
            ->header('Cache-Control', 'no-store, no-cache, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
