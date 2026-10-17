<?php

namespace App\Services\Auth;

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\Google\GoogleIdTokenVerifier;
use App\Support\Auth\Google\InvalidGoogleTokenException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Google sign-in. The app sends a Google ID token; we trust nothing the app says about identity.
 * An existing password account with the same email is NEVER merged automatically.
 */
class GoogleAuthService
{
    public function __construct(
        private GoogleIdTokenVerifier $verifier,
        private MembershipResolver $resolver,
        private AuthSession $session,
        private AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> login data, plus is_new_account */
    public function signIn(string $idToken, ?string $deviceName): array
    {
        $claims = $this->claims($idToken);

        $social = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $claims['sub'])
            ->first();

        $isNew = false;

        if ($social) {
            $user = $social->user;
        } elseif (User::query()->where('email', $claims['email'])->exists()) {
            throw new ApiException(
                'ACCOUNT_LINK_REQUIRED',
                'An account with this email already exists. Log in with your password, then link Google from your account settings.',
                409,
                ['email' => $claims['email']],
            );
        } else {
            $user  = $this->createAccount($claims);
            $isNew = true;
        }

        $membership = $this->resolver->forUser($user);
        $data       = $this->session->issue($user, $membership, $deviceName);

        $this->audit->record($isNew ? 'auth.google_registered' : 'auth.google_login', $user, $membership->business_id, $user);

        return $data + ['is_new_account' => $isNew];
    }

    public function link(User $user, string $idToken): void
    {
        $claims = $this->claims($idToken);

        $taken = SocialAccount::query()
            ->where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $claims['sub'])
            ->exists();

        if ($taken || $user->socialAccounts()->where('provider', SocialAccount::PROVIDER_GOOGLE)->exists()) {
            throw new ApiException('GOOGLE_ALREADY_LINKED', 'This Google account, or a Google account for you, is already linked.', 409);
        }

        try {
            $user->socialAccounts()->create([
                'provider'         => SocialAccount::PROVIDER_GOOGLE,
                'provider_user_id' => $claims['sub'],
                'email'            => $claims['email'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('GOOGLE_ALREADY_LINKED', 'This Google account, or a Google account for you, is already linked.', 409);
        }

        $this->audit->record('auth.google_linked', $user, null, $user);
    }

    public function unlink(User $user): void
    {
        if (!$user->hasPassword()) {
            throw new ApiException(
                'CANNOT_UNLINK_LAST_LOGIN_METHOD',
                'Google is your only way to log in. Create a password first with "Forgot password", then unlink Google.',
                422,
            );
        }

        $user->socialAccounts()->where('provider', SocialAccount::PROVIDER_GOOGLE)->delete();

        $this->audit->record('auth.google_unlinked', $user, null, $user);
    }

    /** @return array{sub: string, email: string, name: string} */
    private function claims(string $idToken): array
    {
        try {
            $claims = $this->verifier->verify($idToken);
        } catch (InvalidGoogleTokenException) {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed. Please try again.', 401);
        }

        $sub   = (string) ($claims['sub'] ?? '');
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));

        if ($sub === '' || $email === '') {
            throw new ApiException('GOOGLE_TOKEN_INVALID', 'Google sign-in failed. Please try again.', 401);
        }

        if (!filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new ApiException('GOOGLE_EMAIL_NOT_VERIFIED', 'Your Google email address is not verified.', 403);
        }

        return ['sub' => $sub, 'email' => $email, 'name' => trim((string) ($claims['name'] ?? '')) ?: Str::before($email, '@')];
    }

    /** A new Google user gets an account and a default business. The timezone can be changed later. */
    private function createAccount(array $claims): User
    {
        try {
            return DB::transaction(function () use ($claims) {
                $user = User::create(['name' => Str::limit($claims['name'], 120, ''), 'email' => $claims['email'], 'password' => null]);
                $user->forceFill(['email_verified_at' => now()])->save();   // Google already verified this email

                $business = Business::create([
                    'name'     => Str::limit($claims['name'] . "'s business", 120, ''),
                    'timezone' => config('remit.default_business_timezone'),
                ]);

                BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => BusinessRole::Owner]);

                $user->socialAccounts()->create([
                    'provider'         => SocialAccount::PROVIDER_GOOGLE,
                    'provider_user_id' => $claims['sub'],
                    'email'            => $claims['email'],
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('ACCOUNT_LINK_REQUIRED', 'An account with this email already exists. Log in with your password, then link Google from your account settings.', 409, ['email' => $claims['email']]);
        }
    }
}
