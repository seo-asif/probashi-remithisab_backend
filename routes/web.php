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
