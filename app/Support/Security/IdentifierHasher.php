<?php

namespace App\Support\Security;

use RuntimeException;

final class IdentifierHasher
{
    private const KEY_PREFIX = 'base64:';

    /**
     * Generate a deterministic, domain-separated lookup digest.
     *
     * The identifier must already be normalized by the caller.
     */
    public function hash(string $domain, string $identifier): string
    {
        if ($domain === '' || !preg_match('/\A[a-z][a-z0-9_]*\z/D', $domain)) {
            throw new RuntimeException('Invalid identifier hash domain.');
        }

        if ($identifier === '') {
            throw new RuntimeException('Cannot hash an empty identifier.');
        }

        $key = $this->key();

        return hash_hmac(
            'sha256',
            'probashi-remithisab:v1:' . $domain . ':' . $identifier,
            $key
        );
    }

    /**
     * Decode and validate the dedicated HMAC key.
     */
    private function key(): string
    {
        $configured = config('security.identifier_hmac_key');

        if (!is_string($configured)
            || !str_starts_with($configured, self::KEY_PREFIX)) {
            throw new RuntimeException('Identifier HMAC key is not configured correctly.');
        }

        $decoded = base64_decode(
            substr($configured, strlen(self::KEY_PREFIX)),
            true
        );

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Identifier HMAC key must contain 32 bytes.');
        }

        return $decoded;
    }
}
