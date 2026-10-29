<?php

return [
    'api_version'               => 'v1',

    // Used when a business is created without a timezone. Each business keeps ONE timezone.
    'default_business_timezone' => env('REMIT_DEFAULT_TIMEZONE', 'Pacific/Fiji'),

    // How long a login token lives (minutes). Default 30 days.
    'token_ttl_minutes'         => (int) env('REMIT_TOKEN_TTL_MINUTES', 60 * 24 * 30),

    // Email OTP (spec 9.3 and 9.4). All values can be changed in .env.
    'otp'                       => [
        'length'                   => (int) env('OTP_LENGTH', 6),
        'ttl_minutes'              => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts'             => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds'  => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'reset_ticket_ttl_minutes' => (int) env('OTP_RESET_TICKET_TTL_MINUTES', 10),
    ],

// Support verification code.
// Separate from email verification and password-reset OTPs.
    'support_verification'      => [
        'length'                  => (int) env('SUPPORT_CODE_LENGTH', 6),
        'ttl_minutes'             => (int) env('SUPPORT_CODE_TTL_MINUTES', 10),
        'max_attempts'            => (int) env('SUPPORT_CODE_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('SUPPORT_CODE_RESEND_COOLDOWN_SECONDS', 60),
    ],

    // Google sign-in: comma separated OAuth client IDs (Android, iOS, web) that are allowed as token audience.
    'google'                    => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
    ],
];
