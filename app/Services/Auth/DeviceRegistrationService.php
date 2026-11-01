<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceRegistrationService
{
    public function deviceHash(string $androidId): string
    {
        $androidId = strtolower(trim($androidId));

        if (!preg_match('/\A[a-f0-9]{16}\z/', $androidId)) {
            throw new ApiException(
                'DEVICE_ID_INVALID',
                'A valid Android device identifier is required.',
                422
            );
        }

        $key = (string) config('registration_devices.hash_key');

        if (strlen($key) < 32) {
            throw new ApiException(
                'CONFIGURATION_ERROR',
                'Device registration protection is not configured.',
                503
            );
        }

        return hash_hmac('sha256', $androidId, $key);
    }

    /**
     * Must be called inside the account-creation transaction.
     */
    public function reserve(
        User $user,
        string $androidId,
        bool $confirmed = false
    ): void {
        $hash = $this->deviceHash($androidId);

        DB::table('registration_devices')->insertOrIgnore([
            'device_hash' => $hash,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $device = DB::table('registration_devices')
            ->where('device_hash', $hash)
            ->lockForUpdate()
            ->first();

        $count = DB::table('device_registrations')
            ->where('registration_device_id', $device->id)
            ->where(function ($query) {
                $query->whereNotNull('confirmed_at')
                    ->orWhere('reserved_until', '>', now());
            })
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($count >= (int) config('registration_devices.max_registrations', 5)) {
            throw new ApiException(
                'DEVICE_REGISTRATION_LIMIT',
                'This device has reached the maximum number of account registrations.',
                422
            );
        }

        DB::table('device_registrations')->insert([
            'registration_device_id' => $device->id,
            'user_id'                => $user->id,
            'reserved_until'         => $confirmed
            ? null
            : now()->addHours(
                (int) config('registration_devices.reservation_hours', 24)
            ),
            'confirmed_at'           => $confirmed ? now() : null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * Must run in the same transaction as account verification.
     */
    public function confirm(User $user): void
    {
        $registration = DB::table('device_registrations')
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (!$registration) {
            throw new ApiException(
                'DEVICE_REGISTRATION_REQUIRED',
                'Device registration information is missing.',
                422
            );
        }

        if ($registration->confirmed_at !== null) {
            return;
        }

        if (
            $registration->reserved_until === null ||
            now()->greaterThanOrEqualTo($registration->reserved_until)
        ) {
            throw new ApiException(
                'DEVICE_RESERVATION_EXPIRED',
                'Your registration has expired. Please register again.',
                422
            );
        }

        DB::table('device_registrations')
            ->where('id', $registration->id)
            ->update([
                'confirmed_at'   => now(),
                'reserved_until' => null,
                'updated_at'     => now(),
            ]);
    }

/**
 * Confirm device registration, or allow a genuine legacy account.
 *
 * Must be called inside the transaction that verifies the email.
 */
    public function confirmOrAllowLegacy(User $user): void
    {
        $registration = DB::table('device_registrations')
            ->where('user_id', $user->id)
            ->first();

        if ($registration !== null) {
            $this->confirm($user);

            return;
        }

        $cutoff = config('registration_devices.enforcement_started_at');

        if (!$cutoff) {
            throw new ApiException(
                'DEVICE_REGISTRATION_REQUIRED',
                'Device registration protection is not fully configured.',
                503
            );
        }

        try {
            $enforcementStartedAt = \Illuminate\Support\Carbon::parse($cutoff);
        } catch (\Throwable $e) {
            throw new ApiException(
                'CONFIGURATION_ERROR',
                'Device registration enforcement time is invalid.',
                503
            );
        }

        if (
            $user->created_at === null ||
            $user->created_at->greaterThanOrEqualTo($enforcementStartedAt)
        ) {
            throw new ApiException(
                'DEVICE_REGISTRATION_REQUIRED',
                'Device registration information is missing.',
                422
            );
        }
    }

}
