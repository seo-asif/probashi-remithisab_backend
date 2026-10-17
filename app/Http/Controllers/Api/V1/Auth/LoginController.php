<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\AuthSession;
use App\Services\Auth\MembershipResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, MembershipResolver $resolver, AuthSession $session, AuditLogger $audit): JsonResponse
    {
        $v    = $request->validated();
        $user = User::query()->where('email', $v['email'])->first();

        // Always run exactly one password hash check, so the response time does not show whether the email exists.
        $hash  = $user && $user->hasPassword() ? $user->password : Hash::make(Str::random(32));
        $valid = Hash::check($v['password'], $hash) && $user && $user->hasPassword();

        if (!$valid) {
            $audit->record('auth.login_failed', $user, null, null, ['email_hash' => hash('sha256', $v['email'])]);

            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        // Checks: account active, email verified, exactly one active business.
        $membership = $resolver->forUser($user);

        $data = $session->issue($user, $membership, $v['device_name'] ?? null);

        $audit->record('auth.login', $user, $membership->business_id, $user);

        return ApiResponse::success($data, 'Login successful.');
    }
}
