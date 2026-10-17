<?php

namespace Tests\Unit;

use App\Support\Auth\OtpCodec;
use PHPUnit\Framework\TestCase;

class OtpCodecTest extends TestCase
{
    public function test_codes_are_numeric_have_the_right_length_and_keep_leading_zeros(): void
    {
        $sawLeadingZero = false;

        for ($i = 0; $i < 3000; $i++) {
            $code = OtpCodec::generate(6);

            $this->assertSame(6, strlen($code));
            $this->assertSame(1, preg_match('/^\d{6}$/', $code));

            if ($code[0] === '0') {
                $sawLeadingZero = true;
            }
        }

        $this->assertTrue($sawLeadingZero, 'about 1 in 10 codes starts with 0');
        $this->assertSame(8, strlen(OtpCodec::generate(8)));
    }

    public function test_only_sensible_lengths_are_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OtpCodec::generate(3);
    }

    public function test_codes_are_random_enough_not_to_repeat_in_a_row(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $codes[OtpCodec::generate(6)] = true;
        }

        $this->assertTrue(count($codes) > 190);
    }

    public function test_the_hash_depends_on_user_purpose_code_and_key(): void
    {
        $base = OtpCodec::hash(1, 'verify_email', '123456', 'key');

        $this->assertSame(64, strlen($base));
        $this->assertSame($base, OtpCodec::hash(1, 'verify_email', '123456', 'key'));
        $this->assertNotSame($base, OtpCodec::hash(2, 'verify_email', '123456', 'key'));
        $this->assertNotSame($base, OtpCodec::hash(1, 'password_reset', '123456', 'key'));
        $this->assertNotSame($base, OtpCodec::hash(1, 'verify_email', '654321', 'key'));
        $this->assertNotSame($base, OtpCodec::hash(1, 'verify_email', '123456', 'other'));
    }

    public function test_matching(): void
    {
        $hash = OtpCodec::hash(7, 'password_reset', '042042', 'secret');

        $this->assertTrue(OtpCodec::matches($hash, 7, 'password_reset', '042042', 'secret'));
        $this->assertFalse(OtpCodec::matches($hash, 7, 'password_reset', '042043', 'secret'));
        $this->assertFalse(OtpCodec::matches($hash, 8, 'password_reset', '042042', 'secret'));
        $this->assertFalse(OtpCodec::matches($hash, 7, 'verify_email', '042042', 'secret'));
    }

    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        OtpCodec::hash(1, 'verify_email', '123456', '');
    }
}
