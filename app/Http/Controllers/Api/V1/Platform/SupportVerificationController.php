<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Support\SupportVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform support identity check only. It never authorizes account changes.
 */
final class SupportVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        SupportVerificationService $verification,
        AuditLogger $audit,
    ): JsonResponse {
        // The route requires authenticated, active, verified platform admin.
        $data = $request->validate([
            'member_no' => ['required', 'integer', 'min:10001'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $adminUser = $request->user();
        $member = User::query()
            ->where('member_no', (int) $data['member_no'])
            ->first();

        // Do not distinguish unknown accounts, ineligible accounts, and wrong codes.
        $eligible = $member !== null
            && $member->status === AccountStatus::Active
            && $member->hasVerifiedEmail();

        $verified = $eligible
            && $verification->verify($member, $data['code']);

        if (!$verified) {
            $audit->record(
                'support.platform_verification_failed',
                $adminUser,
                null,
                $member,
            );

            throw new ApiException(
                'SUPPORT_CODE_INVALID',
                'Member number or support code is invalid or expired.',
                422,
            );
        }

        $audit->record(
            'support.platform_verification_succeeded',
            $adminUser,
            null,
            $member,
        );

        return ApiResponse::success(
            [
                'verified' => true,
                'member_no' => (int) $member->member_no,
            ],
            'Support identity verified.',
        );
    }
}
