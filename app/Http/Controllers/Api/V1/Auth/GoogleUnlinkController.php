<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CurrentPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GoogleUnlinkController extends Controller
{
    /** Removes Google login. Refused when it is the account's only way to log in. */
    public function __invoke(CurrentPasswordRequest $request, GoogleAuthService $google): JsonResponse
    {
        $user = $request->user();

        if ($user->hasPassword() && !Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $google->unlink($user);

        return ApiResponse::success(null, 'Google account unlinked.');
    }
}
