<?php

namespace Tests\Unit;

use App\Support\Validation\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_length_counts_characters_the_way_a_person_does(): void
    {
        $this->assertSame(6, Text::graphemeLength('Mother'));
        $this->assertSame(1, Text::graphemeLength("e\u{301}"));          // e + accent is one character
        $this->assertSame(1, Text::graphemeLength('👨‍👩‍👧'));              // one emoji family
        $this->assertSame(3, Text::graphemeLength('রহিম'));              // Bangla: র, হি, ম
        $this->assertSame(0, Text::graphemeLength(''));
    }

    public function test_code_is_detected_like_the_app(): void
    {
        foreach (['<script>alert(1)</script>', '<b>x</b>', 'javascript:alert(1)', 'a`b', '${x}', '<% x %>', 'eval (x)', 'please import (file)'] as $text) {
            $this->assertTrue(Text::containsCode($text), $text);
        }
        foreach (['Paid via bKash', 'Send 50 > 40', 'Rate 72.5', 'Mother', 'call me at 7pm (Fiji)', ''] as $text) {
            $this->assertFalse(Text::containsCode($text), $text);
        }
    }

    public function test_person_names(): void
    {
        $this->assertNull(Text::personNameError('Mother'));
        $this->assertNull(Text::personNameError("O'Brien-Smith Jr."));
        $this->assertNull(Text::personNameError('Ab'));
        $this->assertNull(Text::personNameError('A'));
        $this->assertNull(Text::personNameError('মা'));                       // Mother: one character, allowed
        $this->assertNull(Text::personNameError('রহিম'));
        $this->assertNull(Text::personNameError(str_repeat('a', 20)));

        $this->assertSame('Family member name is required.', Text::personNameError(''));
        $this->assertSame('Family member name is required.', Text::personNameError('   '));
        $this->assertSame('Name must contain 1–20 characters.', Text::personNameError(str_repeat('a', 21)));
        $this->assertSame('Name must contain 2–20 characters.', Text::personNameError('A', 2, 20));   // the prototype app's old minimum
        $this->assertSame("Use letters, spaces, . - and ' only.", Text::personNameError('Rahim123'));
        $this->assertSame("Use letters, spaces, . - and ' only.", Text::personNameError('Rahim <b>'));
        $this->assertSame("Use letters, spaces, . - and ' only.", Text::personNameError('Mum_2'));
    }
}
