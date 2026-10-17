# Phase 2: Authentication (spec v2.3, sections 9 and 15)

Status: written, syntax-checked, NOT run inside Laravel. Run `php artisan test` and send me the result.
Run here (outside Laravel): the OtpCodec tests (6 tests) passed. Everything else needs your Laravel install.

## What I found in your project
Your project already contained an auth layer I did not write. It used Laravel's signed email links and reset tokens, and:
- registration returned a login token BEFORE the email was verified (spec 9.1 says no access until verified)
- no OTP codes (spec 9.3 and 9.4 require them), no audit log, no Google sign-in
- success responses had no `code` field, and tokens never expired
- login did not check the email, had no per-email rate limit, and its timing showed whether an email exists
This phase replaces it. Your Phase 1 files (tenancy, Sender privacy, timezones) are kept as they were.

## Install (local)
1. Unzip over your project (overwrite when asked).
2. Delete the files in `REMOVE.txt`.
3. Merge `.env.example.additions` into `.env`. Set `QUEUE_CONNECTION=sync` while developing.
4. `composer require google/auth`   (only needed for real Google sign-in; the tests do not need it)
5. `php artisan migrate:fresh` then `php artisan test`
   (`migrate:fresh` is fine on a development database. It erases it.)

## Flows (what the Flutter app calls)
Register and verify
1. POST /api/v1/auth/register  {name, email, password, password_confirmation, business_name, business_phone?, country_code, timezone}
   -> 201, NO token. data: {user, business, email_verification_required: true, otp: {expires_in_seconds, resend_after_seconds}}
2. POST /api/v1/auth/email/verify  {email, code}  -> 200 {email_verified: true}
   Wrong, expired, used or unknown: always 422 OTP_INVALID with the same message.
3. POST /api/v1/auth/email/resend  {email}  -> always 200 with the same message and timings
4. POST /api/v1/auth/login  {email, password, device_name?}
   -> 200 data: {token, token_type: "Bearer", expires_at (UTC), user, business, role}
   Errors: 401 INVALID_CREDENTIALS (same for wrong password and unknown email), 403 EMAIL_NOT_VERIFIED,
   403 ACCOUNT_SUSPENDED, 403 NO_BUSINESS, 403 BUSINESS_SUSPENDED, 409 BUSINESS_SELECTION_REQUIRED, 429 TOO_MANY_REQUESTS.
   The app can log in right after verification by keeping the password in memory for that one screen flow.

Forgot password
1. POST /api/v1/auth/password/forgot  {email}  -> always 200 (same answer for known and unknown emails)
2. POST /api/v1/auth/password/verify-code  {email, code}  -> 200 {reset_token, expires_in_seconds}
3. POST /api/v1/auth/password/reset  {email, reset_token, password, password_confirmation}
   -> 200. Every device is logged out, a "password changed" email is sent. Wrong or expired ticket: 422 RESET_TOKEN_INVALID.

Logged in
- POST /api/v1/auth/logout            this device only
- POST /api/v1/auth/logout-all        every device
- POST /api/v1/auth/change-password   {current_password, password, password_confirmation}: every device is logged out
- GET  /api/v1/me                     needs a verified email, an active account and one active business

Google
- POST   /api/v1/auth/google         {id_token, device_name?}: new user gets an account and a default business ("<name>'s business", default timezone), data.is_new_account tells the app so it can ask for the business name and timezone
- POST   /api/v1/auth/google/link    (logged in) {id_token, current_password}
- DELETE /api/v1/auth/google         (logged in) {current_password}: refused with CANNOT_UNLINK_LAST_LOGIN_METHOD if Google is the only way in
- An existing password account with the same email is NEVER merged: 409 ACCOUNT_LINK_REQUIRED. The user logs in with the password, then links Google.
- If a Google account becomes unavailable: use "Forgot password". It creates a password, so the business data stays reachable.

## Security decisions
- Codes: 6 digits from random_int, valid 5 minutes, 5 wrong tries lock the code, 60 second resend cooldown, a new code cancels the old one. All configurable.
- Only an HMAC of the code is stored (user, purpose and code, keyed with APP_KEY). The code travels only inside an ENCRYPTED queued job (it is not readable in the jobs table).
- A correct code is consumed in the same locked database step, so two simultaneous requests cannot both succeed.
- No account enumeration on codes and password reset: same status and body for known and unknown emails. Limits apply to unknown emails too.
- Login always runs one password hash check, so timing does not reveal whether an email exists. Failed logins are logged with a hash of the email, never the email or password.
- Verifying an email does NOT log the user in. Reading an inbox alone never opens an account.
- Password reset by code proves the inbox, so it also marks the email verified.
- Changing or resetting a password deletes every login token.
- Registration does say "email already registered" (a normal trade-off, otherwise nobody could tell why registration failed). The code endpoints do not reveal anything.
- Audit log (`audit_logs`): register, email verified, login, failed login, logout, logout all, password changed, reset requested, reset done, Google registered, linked, unlinked. Never passwords, codes or tokens.

## Rate limits (per minute unless stated)
login 5 per email+IP and 30 per IP; register 10 per hour per IP; send a code 1 per email+IP, 5 per hour per email, 30 per hour per IP; verify a code 10 per 10 minutes per email+IP and 30 per IP.

## Google Cloud setup (when you are ready)
1. console.cloud.google.com: create a project, configure the OAuth consent screen.
2. Create OAuth client IDs: Android (package name + SHA-1), iOS (bundle ID) and Web.
3. Put all IDs in `GOOGLE_CLIENT_IDS`, comma separated. The server accepts a token only if it was made for one of them.
4. The Flutter app signs in with Google and sends the `id_token` to /auth/google.

## Not built yet
- "New login" security email (optional in the spec)
- Postman collection and full API document (Phase 7)
- Business timezone and name change endpoint (needed so a Google user can fix the default business; Phase 3)

## Before production (later, when you move to Hostinger)
Set a real mail provider (MAIL_MAILER must not be log or array: the job refuses to run in production), run a queue worker or cron, APP_DEBUG=false, GOOGLE_CLIENT_IDS, and check that APP_KEY never changes (a changed key makes pending codes unreadable).
