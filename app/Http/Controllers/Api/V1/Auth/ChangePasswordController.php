<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Mail\PasswordChangedMail;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ChangePasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasPassword()) {
            throw new ApiException('PASSWORD_NOT_SET', 'This account has no password yet. Use "Forgot password" to create one.', 422);
        }

        if (!Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => $request->validated('password')])->save();   // hashed by the model cast
            $user->tokens()->delete();                                                  // every device, including this one
        });

        Mail::to($user->email)->queue(new PasswordChangedMail($user->name));

        $audit->record('auth.password_changed', $user, null, $user);

        return ApiResponse::success(null, 'Password changed. Please log in again.');
    }
}
