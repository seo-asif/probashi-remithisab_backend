<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

class VerifyEmailController extends Controller
{
    /** Wrong code, expired code, used code, unknown email: all give the same answer. */
    public function __invoke(VerifyCodeRequest $request, OtpService $otp, AuditLogger $audit): JsonResponse
    {
        $v    = $request->validated();
        $user = User::query()->where('email', $v['email'])->first();

        $ok = $user && !$user->hasVerifiedEmail() && $otp->verify($user, OtpCode::PURPOSE_VERIFY_EMAIL, $v['code']);

        if (!$ok) {
            throw new ApiException('OTP_INVALID', 'The code is incorrect or has expired. Please request a new code.', 422);
        }

        $user->markEmailAsVerified();

        $audit->record('auth.email_verified', $user, null, $user);

        // No token here on purpose: the app logs in with the password, so reading an inbox alone never opens an account.
        return ApiResponse::success(['email_verified' => true], 'Email verified. You can log in now.');
    }
}
