<?php

namespace App\Support\Auth\Google;

interface GoogleIdTokenVerifier
{
    /**
     * Checks signature, issuer, audience and expiry of a Google ID token.
     *
     * @return array<string, mixed> the verified claims (sub, email, email_verified, name, ...)
     * @throws InvalidGoogleTokenException when the token is not valid
     */
    public function verify(string $idToken): array;
}
