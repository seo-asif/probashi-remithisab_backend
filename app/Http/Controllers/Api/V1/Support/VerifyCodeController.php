<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\VerifySupportCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuditLogger;
use App\Services\Support\SupportVerificationService;
use Illuminate\Http\JsonResponse;

class VerifyCodeController extends Controller
{
    public function __invoke(
        VerifySupportCodeRequest $request,
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

        $verified = $verification->verify(
            $user,
            $request->validated('code')
        );

        if (!$verified) {
            $audit->record(
                'support.verification_failed',
                $user,
                null,
                $user
            );

            throw new ApiException(
                'SUPPORT_CODE_INVALID',
                'The support verification code is incorrect or has expired.',
                422
            );
        }

        $audit->record(
            'support.verification_succeeded',
            $user,
            null,
            $user
        );

        return ApiResponse::success(
            ['verified' => true],
            'Support verification successful.'
        );
    }
}