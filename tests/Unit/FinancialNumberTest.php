<?php

namespace Tests\Unit;

use App\Support\Money\FinancialNumber;
use PHPUnit\Framework\TestCase;

class FinancialNumberTest extends TestCase
{
    public function test_valid_amounts(): void
    {
        foreach (['10', '10.5', '10.55', '0.01', '0', ' 7 ', 10, 10.5, 72.5] as $value) {
            $this->assertTrue(FinancialNumber::isValid($value, 2), var_export($value, true));
        }
    }

    public function test_invalid_amounts(): void
    {
        foreach (['', ' ', '-1', '+1', '1e3', '1,000', '.5', '5.', 'abc', '10.555', '1 0', null, true, [], -3, -1.5] as $value) {
            $this->assertFalse(FinancialNumber::isValid($value, 2), var_export($value, true));
        }
    }

    public function test_the_decimal_limit_is_optional(): void
    {
        $this->assertTrue(FinancialNumber::isValid('10.555'));
        $this->assertTrue(FinancialNumber::isValid('10.555', 3));
        $this->assertFalse(FinancialNumber::isValid('10.555', 2));
        $this->assertFalse(FinancialNumber::isValid('10.5', 0));
    }
}
