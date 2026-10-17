<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Mail\PasswordChangedMail;
use App\Models\PasswordResetTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private const EMAIL = 'owner@example.com';

    private function forgot(string $email = self::EMAIL)
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
    }

    private function verifyCode(string $code, string $email = self::EMAIL)
    {
        return $this->postJson('/api/v1/auth/password/verify-code', ['email' => $email, 'code' => $code]);
    }

    private function reset(string $ticket, array $override = [])
    {
        return $this->postJson('/api/v1/auth/password/reset', $override + [
            'email'                 => self::EMAIL,
            'reset_token'           => $ticket,
            'password'              => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
        ]);
    }

    /** Runs forgot + verify-code and returns the one-time reset ticket. */
    private function ticket(): string
    {
        $this->forgot()->assertOk();

        return $this->verifyCode($this->lastOtp())->assertOk()->json('data.reset_token');
    }

    public function test_the_full_flow_changes_the_password_and_logs_every_device_out(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->createToken('phone');

        $this->reset($this->ticket())->assertOk()->assertJsonPath('code', 'OK');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Mail::assertQueued(PasswordChangedMail::class, fn ($mail) => $mail->hasTo(self::EMAIL));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset']);

        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => 'NewSecret456'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => 'Secret123'])->assertStatus(401);
    }

    public function test_forgot_password_gives_the_same_answer_for_known_and_unknown_emails(): void
    {
        Mail::fake();
        $this->makeUser();

        $known   = $this->forgot();
        $unknown = $this->forgot('nobody@example.com');

        $known->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertCount(1, $this->otpMails());   // only the real account got an email
    }

    public function test_no_code_is_sent_to_a_suspended_account_but_the_answer_looks_the_same(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->forgot()->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_wrong_code_gives_no_ticket(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->forgot()->assertOk();
        $code  = $this->lastOtp();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->verifyCode($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertDatabaseCount('password_reset_tickets', 0);
    }

    public function test_an_unknown_email_is_refused_exactly_like_a_wrong_code(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->forgot()->assertOk();

        $known   = $this->verifyCode('000000');
        $unknown = $this->verifyCode('000000', 'nobody@example.com');

        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_the_code_works_once_and_expires(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->forgot()->assertOk();
        $code = $this->lastOtp();

        $this->verifyCode($code)->assertOk();
        $this->verifyCode($code)->assertStatus(422);

        $this->travel(6)->minutes();
        $this->forgot()->assertOk();   // limiter window has passed
        $old = $this->lastOtp();
        $this->travel(6)->minutes();
        $this->verifyCode($old)->assertStatus(422);
    }

    public function test_a_ticket_works_once(): void
    {
        Mail::fake();
        $this->makeUser();
        $ticket = $this->ticket();

        $this->reset($ticket)->assertOk();
        $this->reset($ticket, ['password' => 'Another789x', 'password_confirmation' => 'Another789x'])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_ticket_expires_after_ten_minutes(): void
    {
        Mail::fake();
        $this->makeUser();
        $ticket = $this->ticket();

        $this->travel(11)->minutes();

        $this->reset($ticket)->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_wrong_ticket_and_an_unknown_email_look_the_same(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->ticket();

        $wrong   = $this->reset('not-the-ticket');
        $unknown = $this->reset('not-the-ticket', ['email' => 'nobody@example.com']);

        $wrong->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
        $this->assertSame($wrong->json(), $unknown->json());
    }

    public function test_only_the_hash_of_the_ticket_is_stored(): void
    {
        Mail::fake();
        $this->makeUser();
        $ticket = $this->ticket();

        $row = PasswordResetTicket::firstOrFail();
        $this->assertNotSame($ticket, $row->token_hash);
        $this->assertSame(hash('sha256', $ticket), $row->token_hash);
    }

    public function test_the_new_password_must_be_strong_and_confirmed(): void
    {
        Mail::fake();
        $this->makeUser();
        $ticket = $this->ticket();

        $this->reset($ticket, ['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->reset($ticket, ['password_confirmation' => 'Other12345'])->assertStatus(422)->assertJsonValidationErrors(['password']);

        // the ticket was not used up by the failed attempts
        $this->reset($ticket)->assertOk();
    }

    public function test_resetting_also_verifies_an_unverified_email(): void
    {
        Mail::fake();
        $this->makeUnverifiedUser();

        $this->reset($this->ticket())->assertOk();

        $this->assertNotNull(User::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_a_google_only_account_can_create_a_password_this_way(): void
    {
        Mail::fake();
        $this->makeUser(['password' => null]);

        $this->reset($this->ticket())->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => 'NewSecret456'])->assertOk();
    }
}
