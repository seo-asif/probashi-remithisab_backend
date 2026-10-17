<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\Auth\OtpTimings;
use Illuminate\Http\JsonResponse;

class ResendEmailCodeController extends Controller
{
    /** Always the same answer, so it cannot be used to find out which emails have accounts. */
    public function __invoke(EmailRequest $request, OtpService $otp): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user && $user->status === AccountStatus::Active && !$user->hasVerifiedEmail()) {
            $otp->issue($user, OtpCode::PURPOSE_VERIFY_EMAIL);
        }

        return ApiResponse::success(OtpTimings::data(), 'If this email needs verification, we sent a new code.');
    }
}
