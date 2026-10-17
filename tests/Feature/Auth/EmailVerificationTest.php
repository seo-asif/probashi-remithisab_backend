<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private function register(): string
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        return $this->lastOtp();
    }

    private function verify(string $code, string $email = 'asif@example.com')
    {
        return $this->postJson('/api/v1/auth/email/verify', ['email' => $email, 'code' => $code]);
    }

    public function test_the_correct_code_verifies_the_email_and_gives_no_token(): void
    {
        $code = $this->register();

        $this->verify($code)
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonMissingPath('data.token');

        $this->assertNotNull(User::where('email', 'asif@example.com')->first()->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified']);
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $code  = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->verify($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        $this->assertSame(1, OtpCode::first()->attempts);
        $this->assertNull(User::where('email', 'asif@example.com')->first()->email_verified_at);
    }

    public function test_five_wrong_attempts_lock_the_code_even_for_the_right_answer(): void
    {
        $code  = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->verify($wrong)->assertStatus(422);
        }

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertNull(User::where('email', 'asif@example.com')->first()->email_verified_at);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->register();

        $this->travel(6)->minutes();

        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_a_code_works_only_once(): void
    {
        $code = $this->register();

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_a_known_email_and_an_unknown_email_get_the_same_answer(): void
    {
        $code  = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        $known   = $this->verify($wrong);
        $unknown = $this->verify($wrong, 'nobody@example.com');

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_a_new_code_replaces_the_old_one(): void
    {
        $first = $this->register();

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/email/resend', ['email' => 'asif@example.com'])
            ->assertOk()
            ->assertJsonPath('data.resend_after_seconds', 60);

        $second = $this->lastOtp();
        $this->assertCount(2, $this->otpMails());

        if ($first !== $second) {
            $this->verify($first)->assertStatus(422);
        }
        $this->verify($second)->assertOk();
    }

    public function test_resend_gives_the_same_answer_for_unknown_and_verified_emails_and_sends_nothing_for_them(): void
    {
        Mail::fake();
        $this->makeUser(['email' => 'verified@example.com']);

        $known   = $this->postJson('/api/v1/auth/email/resend', ['email' => 'verified@example.com']);
        $unknown = $this->postJson('/api/v1/auth/email/resend', ['email' => 'nobody@example.com']);

        $this->assertSame($known->json(), $unknown->json());
        Mail::assertNothingSent();
    }

    public function test_resend_is_limited_to_one_per_minute(): void
    {
        $this->register();
        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/email/resend', ['email' => 'asif@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/email/resend', ['email' => 'asif@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertJsonStructure(['data' => ['retry_after_seconds']]);

        $this->assertCount(2, $this->otpMails());
    }

    public function test_a_malformed_code_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/auth/email/verify', ['email' => 'asif@example.com', 'code' => '12ab'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonValidationErrors(['code']);
    }

    public function test_an_unverified_account_cannot_use_business_features_even_with_a_token(): void
    {
        Sanctum::actingAs($this->makeUnverifiedUser());

        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }
}
