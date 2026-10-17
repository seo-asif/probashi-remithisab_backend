<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class VerifyResetCodeController extends Controller
{
    /** Step 2. A correct code gives a one-time reset ticket that is valid for a few minutes. */
    public function __invoke(VerifyCodeRequest $request, OtpService $otp, PasswordResetService $resets): JsonResponse
    {
        $v    = $request->validated();
        $user = User::query()->where('email', $v['email'])->first();

        $ok = $user && $user->status === AccountStatus::Active && $otp->verify($user, OtpCode::PURPOSE_PASSWORD_RESET, $v['code']);

        if (!$ok) {
            throw new ApiException('OTP_INVALID', 'The code is incorrect or has expired. Please request a new code.', 422);
        }

        return ApiResponse::success([
            'reset_token'        => $resets->issueTicket($user),
            'expires_in_seconds' => (int) config('remit.otp.reset_ticket_ttl_minutes') * 60,
        ], 'Code accepted. Choose a new password.');
    }
}
