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

// Phase 4.2: verified administrator account settings and password change.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'show'])
        ->name('admin.settings');
    \Illuminate\Support\Facades\Route::post('/admin/settings/password', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'changePassword'])
        ->middleware('throttle:5,1')->name('admin.settings.password');
});

// Phase 4.4: protected browser interface for the existing Daily Messages table.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'index'])->name('admin.daily-messages');
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages/create', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'create'])->name('admin.daily-messages.create');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'store'])->middleware('throttle:30,1')->name('admin.daily-messages.store');
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages/{message}/edit', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'edit'])->whereNumber('message')->name('admin.daily-messages.edit');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages/{message}', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'update'])->whereNumber('message')->middleware('throttle:30,1')->name('admin.daily-messages.update');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages/{message}/status', [\App\Http\Controllers\Admin\DailyMessagesController::class, 'status'])->whereNumber('message')->middleware('throttle:30,1')->name('admin.daily-messages.status');
});

// Phase 4.2 Pass 2: MFA-verified regeneration of administrator recovery codes.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->post('/admin/settings/recovery-codes', [\App\Http\Controllers\Admin\AdminRecoveryCodesController::class, 'regenerate'])
    ->middleware('throttle:5,10')->name('admin.settings.recovery.regenerate');

// Phase 4.4 Pass 2: browser CSV tools, bulk publication, and likes analytics.
\Illuminate\Support\Facades\Route::middleware([
    'auth', 'platform.admin', \App\Http\Middleware\RequireAdminMfa::class,
])->group(function () {
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages/tools', [\App\Http\Controllers\Admin\DailyMessagesToolsController::class, 'index'])->name('admin.daily-messages.tools');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages/import/preview', [\App\Http\Controllers\Admin\DailyMessagesToolsController::class, 'preview'])->middleware('throttle:10,1')->name('admin.daily-messages.import.preview');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages/import', [\App\Http\Controllers\Admin\DailyMessagesToolsController::class, 'import'])->middleware('throttle:10,1')->name('admin.daily-messages.import.commit');
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages/export', [\App\Http\Controllers\Admin\DailyMessagesToolsController::class, 'export'])->middleware('throttle:15,1')->name('admin.daily-messages.export');
    \Illuminate\Support\Facades\Route::post('/admin/daily-messages/bulk-status', [\App\Http\Controllers\Admin\DailyMessagesToolsController::class, 'bulkStatus'])->middleware('throttle:20,1')->name('admin.daily-messages.bulk-status');
    \Illuminate\Support\Facades\Route::get('/admin/daily-messages/analytics', [\App\Http\Controllers\Admin\DailyMessagesAnalyticsController::class, 'index'])->name('admin.daily-messages.analytics');
});
