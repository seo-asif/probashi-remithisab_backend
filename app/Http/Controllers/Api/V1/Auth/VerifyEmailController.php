<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\DeviceRegistrationService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    public function __invoke(
        VerifyCodeRequest $request,
        OtpService $otp,
        AuditLogger $audit,
        DeviceRegistrationService $devices
    ): JsonResponse {
        $v = $request->validated();

        $verified = DB::transaction(function () use ($v, $otp, $devices) {
            $user = User::query()
                ->where('email', $v['email'])
                ->lockForUpdate()
                ->first();

            if (!$user || $user->hasVerifiedEmail()) {
                return false;
            }

            $ok = $otp->verify(
                $user,
                OtpCode::PURPOSE_VERIFY_EMAIL,
                $v['code']
            );

            if (!$ok) {
                // Commit the failed OTP attempt counter.
                return false;
            }

            // These operations must succeed together.
            $devices->confirmOrAllowLegacy($user);
            $user->markEmailAsVerified();

            return true;
        });

        if (!$verified) {
            throw new ApiException(
                'OTP_INVALID',
                'The code is incorrect or has expired. Please request a new code.',
                422
            );
        }

        $user = User::query()
            ->where('email', $v['email'])
            ->firstOrFail();

        $audit->record('auth.email_verified', $user, null, $user);

        return ApiResponse::success(
            ['email_verified' => true],
            'Email verified. You can log in now.'
        );
    }
}
