<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuditLogger;
use App\Services\Support\SupportVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationCodeController extends Controller
{
    public function __invoke(
        Request $request,
        SupportVerificationService $verification,
        AuditLogger $audit
    ): JsonResponse {
        $user = $request->user();

        if (
            $user === null
            || $user->status !== AccountStatus::Active
            || !$user->hasVerifiedEmail()
        ) {
            throw new ApiException(
                'SUPPORT_VERIFICATION_FORBIDDEN',
                'Support verification is not available for this account.',
                403
            );
        }

        $data = $verification->issue($user);

        $audit->record(
            'support.verification_code_issued',
            $user,
            null,
            $user
        );

        return ApiResponse::success(
            $data,
            'Support verification code created.'
        );
    }
}