<?php

namespace Tests\Unit\Security;

use App\Support\Security\IdentifierNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentifierNormalizerTest extends TestCase
{
    public function test_customer_phone_formats_are_equivalent(): void
    {
        $expected = '01712345678';

        $this->assertSame(
            $expected,
            IdentifierNormalizer::customerPhone('01712345678')
        );

        $this->assertSame(
            $expected,
            IdentifierNormalizer::customerPhone('+8801712345678')
        );

        $this->assertSame(
            $expected,
            IdentifierNormalizer::customerPhone('8801712345678')
        );
    }

    public function test_wallet_formats_are_equivalent(): void
    {
        foreach ([
            '01712345678',
            '+8801712345678',
            '8801712345678',
            '88 01712345678',
        ] as $input) {
            $this->assertSame(
                '01712345678',
                IdentifierNormalizer::wallet($input)
            );
        }
    }

    public function test_rocket_twelve_digit_number_is_preserved(): void
    {
        $this->assertSame(
            '017123456789',
            IdentifierNormalizer::wallet('017123456789')
        );

        $this->assertSame(
            '017123456789',
            IdentifierNormalizer::wallet('+88017123456789')
        );
    }

    public function test_bank_account_preserves_leading_zeros(): void
    {
        $this->assertSame(
            '0012345678',
            IdentifierNormalizer::bankAccount('0012345678')
        );

        $this->assertSame(
            '0012345678',
            IdentifierNormalizer::bankAccount('0012-345-678')
        );
    }

    public function test_different_bank_accounts_remain_distinct(): void
    {
        $this->assertNotSame(
            IdentifierNormalizer::bankAccount('0012345678'),
            IdentifierNormalizer::bankAccount('12345678')
        );
    }

    public function test_empty_wallet_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentifierNormalizer::wallet('');
    }

    public function test_empty_customer_phone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentifierNormalizer::customerPhone('');
    }

    public function test_non_numeric_bank_account_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdentifierNormalizer::bankAccount('123ABC456');
    }
}
