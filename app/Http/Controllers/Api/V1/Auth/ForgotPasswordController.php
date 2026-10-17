<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\OtpService;
use App\Support\Auth\OtpTimings;
use Illuminate\Http\JsonResponse;

class ForgotPasswordController extends Controller
{
    /** Step 1. Always the same answer, whether or not the email has an account. */
    public function __invoke(EmailRequest $request, OtpService $otp, AuditLogger $audit): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user && $user->status === AccountStatus::Active && $otp->issue($user, OtpCode::PURPOSE_PASSWORD_RESET)) {
            $audit->record('auth.password_reset_requested', $user, null, $user);
        }

        return ApiResponse::success(OtpTimings::data(), 'If this email has an account, we sent a code to it.');
    }
}
