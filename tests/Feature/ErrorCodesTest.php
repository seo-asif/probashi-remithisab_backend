<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every rejected field has an English message AND a fixed code the Flutter app turns into Bangla. */
class ErrorCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_laravel_rules_get_a_code_from_the_rule_that_failed(): void
    {
        $res = $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email'])->assertStatus(422);

        $this->assertSame('VALIDATION_FAILED', $res->json('code'));
        $this->assertSame(array_keys($res->json('errors')), array_keys($res->json('error_codes')));
        $this->assertContains($res->json('error_codes.email.0'), ['invalid_email', 'invalid_format']);
        $this->assertSame('required', $res->json('error_codes.password.0'));
        $this->assertStringNotContainsString('⟦', json_encode($res->json('errors'), JSON_UNESCAPED_UNICODE));
    }
}
