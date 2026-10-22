<?php

namespace App\Providers;

use App\Support\Auth\Google\GoogleAuthLibraryVerifier;
use App\Support\Auth\Google\GoogleIdTokenVerifier;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentBusiness::class);
        $this->app->bind(GoogleIdTokenVerifier::class, GoogleAuthLibraryVerifier::class);
    }

    public function boot(): void
    {
        // we build the standard envelope ourselves, so resources must not add their own "data" wrapper
        JsonResource::withoutWrapping();

        $email = fn (Request $request) => Str::lower(trim((string) $request->input('email', '')));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // The limits apply the same way to known and unknown emails, so they reveal nothing.
        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perHour(10)->by('register:' . $request->ip()),
        ]);

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:' . $email($request) . '|' . $request->ip()),
            Limit::perMinute(30)->by('login-ip:' . $request->ip()),
        ]);

        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perMinute(1)->by('otp-send:' . $email($request) . '|' . $request->ip()),
            Limit::perHour(5)->by('otp-send-email:' . $email($request)),
            Limit::perHour(30)->by('otp-send-ip:' . $request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinutes(10, 10)->by('otp-verify:' . $email($request) . '|' . $request->ip()),
            Limit::perMinute(30)->by('otp-verify-ip:' . $request->ip()),
        ]);

        RateLimiter::for('support-code-send', fn (Request $request) => [
    Limit::perMinute(1)->by(
        'support-send-user:' . ($request->user()?->id ?: $request->ip())
    ),
    Limit::perHour(5)->by(
        'support-send-hour:' . ($request->user()?->id ?: $request->ip())
    ),
]);

RateLimiter::for('support-code-verify', fn (Request $request) => [
    Limit::perMinutes(10, 10)->by(
        'support-verify-user:' . ($request->user()?->id ?: $request->ip())
    ),
    Limit::perMinute(30)->by(
        'support-verify-ip:' . $request->ip()
    ),
]);
    }
}
