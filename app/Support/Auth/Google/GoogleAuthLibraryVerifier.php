<?php

namespace App\Support\Auth\Google;

use App\Exceptions\ApiException;

/**
 * Verifies Google ID tokens with Google's own maintained library (composer require google/auth).
 * The library checks the signature against Google's public keys, the issuer and the expiry.
 * We check the audience against OUR client IDs, so a token made for another app is refused.
 */
final class GoogleAuthLibraryVerifier implements GoogleIdTokenVerifier
{
    public function verify(string $idToken): array
    {
        $audiences = (array) config('remit.google.client_ids', []);

        if ($audiences === []) {
            throw new ApiException('CONFIGURATION_ERROR', 'Google sign-in is not configured on the server.', 503);
        }

        $verifier = new \Google\Auth\AccessToken();

        foreach ($audiences as $audience) {
            try {
                $claims = $verifier->verify($idToken, ['audience' => $audience]);
            } catch (\Throwable) {
                $claims = false;
            }

            if (is_array($claims)) {
                return $claims;
            }
        }

        throw new InvalidGoogleTokenException();
    }
}
