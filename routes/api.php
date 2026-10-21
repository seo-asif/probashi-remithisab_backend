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
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Manager\CustomerController;
use App\Http\Controllers\Api\V1\Manager\RemittanceController as ManagerRemittanceController;
use App\Http\Controllers\Api\V1\Manager\SendingNumberController;
use App\Http\Controllers\Api\V1\Manager\TransactionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Plan\PaymentController;
use App\Http\Controllers\Api\V1\Plan\ReferralController;
use App\Http\Controllers\Api\V1\Plan\TrialController;
use App\Http\Controllers\Api\V1\Platform\PaymentProviderSettingController as PlatformPaymentProviderSettingController;
use App\Http\Controllers\Api\V1\Platform\PlanPaymentController as PlatformPlanPaymentController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Sender\GoalController;
use App\Http\Controllers\Api\V1\Sender\RecipientController;
use App\Http\Controllers\Api\V1\Sender\RemittanceController as SenderRemittanceController;
use App\Http\Controllers\Api\V1\Work\WorkController;
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

        Route::post('/plan/trial', TrialController::class);

        Route::controller(PaymentController::class)->group(function () {
            Route::get('/payments/options', 'options');
            Route::get('/payments', 'index');
            Route::post('/payments', 'store');
        });

        Route::controller(ReferralController::class)->group(function () {
            Route::get('/referrals', 'show');
            Route::post('/referrals/claim', 'claim');
        });

        Route::patch('/business', [BusinessController::class, 'update']);

        // ---- Remittance Manager mode (shared by everyone in the business)
        Route::controller(CustomerController::class)->group(function () {
            Route::get('/customers', 'index');
            Route::post('/customers', 'store');
            Route::get('/customers/{id}', 'show');
            Route::patch('/customers/{id}', 'update');
            Route::delete('/customers/{id}', 'destroy');
            Route::post('/customers/{id}/restore', 'restore');
        });
        Route::controller(TransactionController::class)->group(function () {
            Route::get('/manager-transactions', 'index');
            Route::post('/manager-transactions', 'store');
            Route::get('/manager-transactions/{id}', 'show');
            Route::patch('/manager-transactions/{id}', 'update');
            Route::patch('/manager-transactions/{id}/status', 'status');
            Route::delete('/manager-transactions/{id}', 'destroy');
            Route::post('/manager-transactions/{id}/restore', 'restore');
            Route::post('/manager-transactions/{id}/payments', 'addPayment');
            Route::post('/manager-transactions/{id}/payments/{paymentId}/reverse', 'reversePayment');
        });
        Route::controller(ManagerRemittanceController::class)->group(function () {
            Route::get('/manager-remittances', 'index');
            Route::post('/manager-remittances', 'store');
            Route::get('/manager-remittances/{id}', 'show');
            Route::patch('/manager-remittances/{id}', 'update');
            Route::delete('/manager-remittances/{id}', 'destroy');
            Route::post('/manager-remittances/{id}/restore', 'restore');
        });
        Route::controller(SendingNumberController::class)->group(function () {
            Route::get('/sending-numbers', 'index');
            Route::post('/sending-numbers', 'store');
            Route::patch('/sending-numbers/{id}', 'update');
            Route::delete('/sending-numbers/{id}', 'destroy');
        });

        // ---- Sender mode (PRIVATE to the logged-in user)
        Route::controller(RecipientController::class)->group(function () {
            Route::get('/sender-recipients', 'index');
            Route::post('/sender-recipients', 'store');
            Route::get('/sender-recipients/{id}', 'show');
            Route::patch('/sender-recipients/{id}', 'update');
            Route::delete('/sender-recipients/{id}', 'destroy');
            Route::post('/sender-recipients/{id}/restore', 'restore');
        });
        Route::controller(SenderRemittanceController::class)->group(function () {
            Route::get('/sender-remittances', 'index');
            Route::post('/sender-remittances', 'store');
            Route::get('/sender-remittances/{id}', 'show');
            Route::get('/sender-remittances/{id}/copy-info', 'copyInfo');
            Route::patch('/sender-remittances/{id}', 'update');
            Route::post('/sender-remittances/{id}/received', 'received');
            Route::delete('/sender-remittances/{id}', 'destroy');
            Route::post('/sender-remittances/{id}/restore', 'restore');
        });
        Route::controller(GoalController::class)->group(function () {
            Route::get('/savings-goals', 'index');
            Route::post('/savings-goals', 'store');
            Route::patch('/savings-goals/{id}', 'update');
            Route::delete('/savings-goals/{id}', 'destroy');
        });

        // ---- worker tools (PRIVATE)
        Route::controller(WorkController::class)->prefix('work')->group(function () {
            Route::get('/settings', 'settings');
            Route::put('/settings', 'saveSettings');
            Route::get('/days', 'days');
            Route::put('/days/{date}', 'saveDay');
            Route::delete('/days/{date}', 'deleteDay');
            Route::get('/bonuses', 'bonuses');
            Route::post('/bonuses', 'storeBonus');
            Route::patch('/bonuses/{id}', 'updateBonus');
            Route::delete('/bonuses/{id}', 'deleteBonus');
            Route::get('/summary', 'summary');
            Route::get('/salaries', 'salaries');
            Route::get('/salaries/suggest', 'suggestSalary');
            Route::post('/salaries', 'storeSalary');
            Route::patch('/salaries/{id}', 'updateSalary');
            Route::delete('/salaries/{id}', 'deleteSalary');
            Route::get('/loans', 'loans');
            Route::post('/loans', 'storeLoan');
            Route::patch('/loans/{id}', 'updateLoan');
            Route::delete('/loans/{id}', 'deleteLoan');
            Route::get('/spending', 'spending');
            Route::get('/spending/summary', 'spendingSummary');
            Route::post('/spending', 'storeSpending');
            Route::patch('/spending/{id}', 'updateSpending');
            Route::delete('/spending/{id}', 'deleteSpending');
        });

        // ---- reports (business timezone; deleted records never counted)
        Route::controller(ReportController::class)->prefix('reports')->group(function () {
            Route::get('/manager', 'manager');
            Route::get('/manager/monthly', 'managerMonthly');
            Route::get('/manager/home', 'managerHome');
            Route::get('/sender', 'sender');
            Route::get('/sender/monthly', 'senderMonthly');
        });
    });

    /*
     * Platform administration.
     *
     * IMPORTANT:
     * These routes are intentionally outside business.context.
     * Tenant Owner / Administrator roles do not grant access.
     */
    Route::middleware([
        'auth:sanctum',
        'platform.admin',
        'throttle:api',
    ])->prefix('platform')->group(function () {

        Route::controller(PlatformPlanPaymentController::class)
            ->prefix('plan-payments')
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/{id}/approve', 'approve');
                Route::post('/{id}/reject', 'reject');
            });

        Route::controller(
            PlatformPaymentProviderSettingController::class,
        )
            ->prefix('payment-providers')
            ->group(function () {
                Route::get(
                    '/manual-bkash',
                    'showManualBkash',
                );

                Route::put(
                    '/manual-bkash',
                    'updateManualBkash',
                );
            });
    });

});
