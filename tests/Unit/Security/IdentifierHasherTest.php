<?php

namespace Tests\Unit\Security;

use App\Support\Security\IdentifierHasher;
use RuntimeException;
use Tests\TestCase;

final class IdentifierHasherTest extends TestCase
{
    private const TEST_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private IdentifierHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.identifier_hmac_key', self::TEST_KEY);

        $this->hasher = new IdentifierHasher();
    }

    public function test_hash_is_deterministic_and_64_hex_characters(): void
    {
        $first = $this->hasher->hash('customer_phone', '01712345678');
        $second = $this->hasher->hash('customer_phone', '01712345678');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first);
    }

    public function test_different_domains_produce_different_hashes(): void
    {
        $phone = $this->hasher->hash('customer_phone', '01712345678');
        $sending = $this->hasher->hash('sending_number', '01712345678');

        $this->assertNotSame($phone, $sending);
    }

    public function test_different_identifiers_produce_different_hashes(): void
    {
        $first = $this->hasher->hash('customer_phone', '01712345678');
        $second = $this->hasher->hash('customer_phone', '01812345678');

        $this->assertNotSame($first, $second);
    }

    public function test_missing_key_is_rejected(): void
    {
        config()->set('security.identifier_hmac_key', null);

        $this->expectException(RuntimeException::class);

        $this->hasher->hash('customer_phone', '01712345678');
    }

    public function test_invalid_key_length_is_rejected(): void
    {
        config()->set('security.identifier_hmac_key', 'base64:YWJj');

        $this->expectException(RuntimeException::class);

        $this->hasher->hash('customer_phone', '01712345678');
    }

    public function test_invalid_domain_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->hasher->hash('invalid domain', '01712345678');
    }

    public function test_empty_identifier_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->hasher->hash('customer_phone', '');
    }
}
