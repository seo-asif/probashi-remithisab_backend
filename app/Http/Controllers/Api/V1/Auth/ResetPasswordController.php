<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Mail\PasswordChangedMail;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ResetPasswordController extends Controller
{
    /** Step 3. Sets the new password, logs out every device, and tells the user by email. */
    public function __invoke(ResetPasswordRequest $request, PasswordResetService $resets, AuditLogger $audit): JsonResponse
    {
        $v    = $request->validated();
        $user = User::query()->where('email', $v['email'])->first();

        $ok = $user && $user->status === AccountStatus::Active && $resets->reset($user, $v['reset_token'], $v['password']);

        if (!$ok) {
            throw new ApiException('RESET_TOKEN_INVALID', 'This reset has expired or is not valid. Please start again.', 422);
        }

        Mail::to($user->email)->queue(new PasswordChangedMail($user->name));

        $audit->record('auth.password_reset', $user, null, $user);

        return ApiResponse::success(null, 'Password changed. Please log in again.');
    }
}
