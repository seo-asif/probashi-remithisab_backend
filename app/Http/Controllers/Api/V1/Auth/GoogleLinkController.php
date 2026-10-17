<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GoogleLinkController extends Controller
{
    /** Adds Google as a second way to log in. The user must prove they own the account with the current password. */
    public function __invoke(GoogleLinkRequest $request, GoogleAuthService $google): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasPassword()) {
            throw new ApiException('PASSWORD_NOT_SET', 'This account has no password.', 422);
        }

        if (!Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $google->link($user, $request->validated('id_token'));

        return ApiResponse::success(null, 'Google account linked.');
    }
}
