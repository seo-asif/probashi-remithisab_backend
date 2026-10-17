<?php

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GoogleLinkController;
use App\Http\Controllers\Api\V1\Auth\GoogleSignInController;
use App\Http\Controllers\Api\V1\Auth\GoogleUnlinkController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendEmailCodeController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Auth\VerifyResetCodeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// All routes are served under /api/v1
Route::prefix('v1')->group(function () {

    Route::get('/health', HealthController::class)->middleware('throttle:60,1');

    // ---- public: account creation, login, email verification, password reset
    Route::post('/auth/register', RegisterController::class)->middleware('throttle:auth-register');
    Route::post('/auth/login', LoginController::class)->middleware('throttle:auth-login');
    Route::post('/auth/google', GoogleSignInController::class)->middleware('throttle:auth-login');

    Route::post('/auth/email/verify', VerifyEmailController::class)->middleware('throttle:otp-verify');
    Route::post('/auth/email/resend', ResendEmailCodeController::class)->middleware('throttle:otp-send');

    Route::post('/auth/password/forgot', ForgotPasswordController::class)->middleware('throttle:otp-send');
    Route::post('/auth/password/verify-code', VerifyResetCodeController::class)->middleware('throttle:otp-verify');
    Route::post('/auth/password/reset', ResetPasswordController::class)->middleware('throttle:otp-verify');

    // ---- logged in (any account state that holds a token)
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', LogoutController::class);
        Route::post('/auth/logout-all', LogoutAllController::class);
        Route::post('/auth/change-password', ChangePasswordController::class);

        Route::post('/auth/google/link', GoogleLinkController::class);
        Route::delete('/auth/google', GoogleUnlinkController::class);
    });

    // ---- logged in AND inside a business (verified email, active account, one active business)
    Route::middleware(['auth:sanctum', 'business.context', 'throttle:api'])->group(function () {
        Route::get('/me', MeController::class);
    });
});
