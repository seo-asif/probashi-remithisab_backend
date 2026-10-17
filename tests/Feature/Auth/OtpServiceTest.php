<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private OtpService $otp;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->otp  = app(OtpService::class);
        $this->user = $this->makeUnverifiedUser();
    }

    public function test_issue_stores_a_hash_and_emails_the_code(): void
    {
        $this->assertTrue($this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL));

        $code = $this->lastOtp();
        $this->assertSame(1, OtpCode::count());
        $this->assertNotSame($code, OtpCode::first()->code_hash);
        $this->assertTrue($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $code));
    }

    public function test_a_second_code_inside_the_cooldown_is_not_created(): void
    {
        $this->assertTrue($this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL));
        $this->assertFalse($this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL));

        $this->assertSame(1, OtpCode::count());
        $this->assertCount(1, $this->otpMails());
    }

    public function test_a_new_code_after_the_cooldown_replaces_the_old_one(): void
    {
        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);
        $first = $this->lastOtp();

        $this->travel(61)->seconds();
        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);
        $second = $this->lastOtp();

        $this->assertSame(1, OtpCode::whereNull('invalidated_at')->count());
        if ($first !== $second) {
            $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $first));
        }
        $this->assertTrue($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $second));
    }

    public function test_a_code_is_only_good_for_its_own_purpose(): void
    {
        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);
        $code = $this->lastOtp();

        $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_PASSWORD_RESET, $code));
        $this->assertTrue($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $code));
    }

    public function test_a_code_is_only_good_for_its_own_user(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);

        $this->assertFalse($this->otp->verify($other, OtpCode::PURPOSE_VERIFY_EMAIL, $this->lastOtp()));
    }

    public function test_a_correct_code_is_consumed_exactly_once(): void
    {
        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);
        $code = $this->lastOtp();

        $this->assertTrue($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $code));
        $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $code));
        $this->assertNotNull(OtpCode::first()->consumed_at);
    }

    public function test_the_attempt_limit_and_expiry_come_from_configuration(): void
    {
        config(['remit.otp.max_attempts' => 2, 'remit.otp.ttl_minutes' => 1]);

        $this->otp->issue($this->user, OtpCode::PURPOSE_VERIFY_EMAIL);
        $code  = $this->lastOtp();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $wrong));
        $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $wrong));
        $this->assertFalse($this->otp->verify($this->user, OtpCode::PURPOSE_VERIFY_EMAIL, $code));   // locked after 2 tries
        $this->assertSame(2, OtpCode::first()->attempts);
    }
}
