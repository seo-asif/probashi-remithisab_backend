<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
// Initial private administrator foundation. No public admin login route yet.
\Illuminate\Support\Facades\Route::middleware(['auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class])
    ->get('/admin', \App\Http\Controllers\Admin\DashboardController::class)
    ->name('admin.dashboard');

// Password verification only. These routes never grant authenticated dashboard access.
Route::get('/admin/login', [\App\Http\Controllers\Admin\LoginController::class, 'show'])->name('admin.login');
Route::post('/admin/login', [\App\Http\Controllers\Admin\LoginController::class, 'submit'])
    ->middleware('throttle:5,1')->name('admin.login.submit');
Route::get('/admin/mfa', [\App\Http\Controllers\Admin\AdminMfaController::class, 'show'])->name('admin.mfa.pending');
Route::post('/admin/mfa', [\App\Http\Controllers\Admin\AdminMfaController::class, 'submit'])->middleware('throttle:5,1')->name('admin.mfa.submit');
Route::post('/admin/logout', [\App\Http\Controllers\Admin\AdminMfaController::class, 'logout'])->middleware(['auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class])->name('admin.logout');

Route::post('/admin/mfa/recovery', \App\Http\Controllers\Admin\AdminMfaRecoveryController::class)->middleware('throttle:5,1')->name('admin.mfa.recovery');

// Read-only platform user directory. Uses the same admin MFA gate as the overview.
\Illuminate\Support\Facades\Route::middleware([
    'auth',
    'platform.admin',
    \App\Http\Middleware\RequireAdminMfa::class,
])->get('/admin/users', \App\Http\Controllers\Admin\UsersController::class)
    ->name('admin.users');

// Phase 3.3: read-only profile, protected by the same admin security stack.
\Illuminate\Support\Facades\Route::middleware(['auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class])
    ->get('/admin/users/{user}', \App\Http\Controllers\Admin\UserDetailsController::class)
    ->whereNumber('user')
    ->name('admin.users.show');

// Phase 3.5: read-only business management.
\Illuminate\Support\Facades\Route::middleware([
    'auth',
    'platform.admin',
    \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/businesses', \App\Http\Controllers\Admin\BusinessesController::class)
        ->name('admin.businesses');
    \Illuminate\Support\Facades\Route::get('/admin/businesses/{business}', \App\Http\Controllers\Admin\BusinessDetailsController::class)
        ->whereNumber('business')->name('admin.businesses.show');
});

// Phase 3.6: read-only subscription directory.
\Illuminate\Support\Facades\Route::middleware([
    'auth',
    'platform.admin',
    \App\Http\Middleware\RequireAdminMfa::class,
])->get('/admin/subscriptions', \App\Http\Controllers\Admin\SubscriptionsController::class)
  ->name('admin.subscriptions');

// Phase 3.7: read-only payment directory and detail view; privileged actions are not exposed.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/payments', \App\Http\Controllers\Admin\PaymentsController::class)->name('admin.payments');
    \Illuminate\Support\Facades\Route::get('/admin/payments/{payment}', \App\Http\Controllers\Admin\PaymentDetailsController::class)->whereNumber('payment')->name('admin.payments.show');
});

// Phase 3.8: privileged payment decisions use the existing approval service.
\Illuminate\Support\Facades\Route::middleware([
    'auth',
    'platform.admin',
    \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/payments/{payment}/decision', [\App\Http\Controllers\Admin\PaymentDecisionController::class, 'show'])
        ->whereNumber('payment')->name('admin.payments.decision');
    \Illuminate\Support\Facades\Route::post('/admin/payments/{payment}/decision', [\App\Http\Controllers\Admin\PaymentDecisionController::class, 'submit'])
        ->whereNumber('payment')->name('admin.payments.decision.submit');
});

// Phase 4.0: read-only audit history, protected by the established admin MFA gate.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->get('/admin/activity-logs', \App\Http\Controllers\Admin\ActivityLogsController::class)
    ->name('admin.activity-logs');
