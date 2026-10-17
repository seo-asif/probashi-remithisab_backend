<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\OtpService;
use App\Support\Auth\OtpTimings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /** Creates the account, the business and the owner membership together. No login token until the email is verified. */
    public function __invoke(RegisterRequest $request, OtpService $otp, AuditLogger $audit): JsonResponse
    {
        $v = $request->validated();

        try {
            [$user, $business] = DB::transaction(function () use ($v) {
                $user = User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => $v['password']]);

                $business = Business::create([
                    'name'         => $v['business_name'],
                    'phone'        => $v['business_phone'] ?? null,
                    'country_code' => $v['country_code'],
                    'timezone'     => $v['timezone'],
                ]);

                BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => BusinessRole::Owner]);

                return [$user, $business];
            });
        } catch (UniqueConstraintViolationException) {
            // two registrations with the same email at the same moment
            throw ValidationException::withMessages(['email' => ['This email is already registered. Try logging in, or ask for a new verification code.']]);
        }

        $otp->issue($user, OtpCode::PURPOSE_VERIFY_EMAIL);

        $audit->record('auth.register', $user, $business->id, $user);

        return ApiResponse::success([
            'user'                        => UserResource::make($user)->resolve(),
            'business'                    => BusinessResource::make($business)->resolve(),
            'email_verification_required' => true,
            'otp'                         => OtpTimings::data(),
        ], 'Account created. Enter the code we emailed you.', 201);
    }
}
