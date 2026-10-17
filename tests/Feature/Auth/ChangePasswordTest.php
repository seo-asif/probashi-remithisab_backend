<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordChangedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private function change(array $override = [])
    {
        return $this->postJson('/api/v1/auth/change-password', $override + [
            'current_password'      => 'Secret123',
            'password'              => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
        ]);
    }

    public function test_the_password_changes_every_device_is_logged_out_and_an_email_is_queued(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->createToken('phone');
        $user->createToken('tablet');
        Sanctum::actingAs($user);

        $this->change()->assertOk()->assertJsonPath('code', 'OK');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Mail::assertQueued(PasswordChangedMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'NewSecret456'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'Secret123'])->assertStatus(401);
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->makeUser());

        $this->change(['current_password' => 'Wrong12345'])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        Mail::assertNothingQueued();
    }

    public function test_the_confirmation_must_match_and_the_new_password_must_be_different_and_strong(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->change(['password_confirmation' => 'Other12345'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->change(['password' => 'Secret123', 'password_confirmation' => 'Secret123'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->change(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_a_google_only_account_must_create_a_password_through_the_reset_flow(): void
    {
        Sanctum::actingAs($this->makeUser(['password' => null]));

        $this->change()->assertStatus(422)->assertJsonPath('code', 'PASSWORD_NOT_SET');
    }

    public function test_it_needs_a_login(): void
    {
        $this->change()->assertStatus(401);
    }
}
