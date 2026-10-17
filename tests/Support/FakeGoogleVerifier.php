<?php

namespace Tests\Support;

use App\Support\Auth\Google\GoogleIdTokenVerifier;
use App\Support\Auth\Google\InvalidGoogleTokenException;

/** Stands in for Google in tests. Set $claims to what a verified token would contain, or null for an invalid token. */
class FakeGoogleVerifier implements GoogleIdTokenVerifier
{
    public ?array $claims = null;

    public function verify(string $idToken): array
    {
        if ($this->claims === null) {
            throw new InvalidGoogleTokenException();
        }

        return $this->claims;
    }
}
