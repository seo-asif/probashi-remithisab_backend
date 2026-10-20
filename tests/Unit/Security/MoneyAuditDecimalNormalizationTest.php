<?php

namespace Tests\Unit\Security;

use App\Support\Records\MoneyAudit;
use ReflectionMethod;
use Tests\TestCase;

class MoneyAuditDecimalNormalizationTest extends TestCase
{
    private function normalize(mixed $value): ?string
    {
        $method = new ReflectionMethod(MoneyAudit::class, 'norm');

        return $method->invoke(null, $value);
    }

    public function test_equivalent_decimal_representations_match(): void
    {
        $this->assertSame(
            $this->normalize('100.00'),
            $this->normalize('100.0')
        );

        $this->assertSame(
            $this->normalize('100'),
            $this->normalize('100.000')
        );

        $this->assertSame(
            $this->normalize('0'),
            $this->normalize('0.00')
        );
    }

    public function test_trailing_integer_zeros_are_preserved(): void
    {
        $this->assertSame('100', $this->normalize('100'));
        $this->assertSame('1000', $this->normalize('1000'));
        $this->assertSame('10', $this->normalize('10'));
    }

    public function test_large_decimal_values_remain_distinct(): void
    {
        $this->assertNotSame(
            $this->normalize('9007199254740992.00'),
            $this->normalize('9007199254740993.00')
        );
    }

    public function test_decimal_precision_beyond_eight_places_is_preserved(): void
    {
        $this->assertNotSame(
            $this->normalize('1.123456781'),
            $this->normalize('1.123456782')
        );
    }

    public function test_negative_zero_matches_zero(): void
    {
        $this->assertSame(
            $this->normalize('-0.00'),
            $this->normalize('0.00')
        );
    }

    public function test_non_numeric_strings_remain_unchanged(): void
    {
        $this->assertSame(
            'currency_pending',
            $this->normalize('currency_pending')
        );
    }
}
