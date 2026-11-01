<?php

namespace Tests\Feature\Auth;

use App\Enums\BusinessRole;
use App\Mail\OtpMail;
use App\Models\BusinessMembership;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    public function test_registration_creates_user_business_and_owner_but_issues_no_token(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.business.timezone', 'Pacific/Fiji')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.otp.expires_in_seconds', 300)
            ->assertJsonPath('data.otp.resend_after_seconds', 60)
            ->assertJsonMissingPath('data.token');

        $user = User::where('email', 'asif@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $membership = BusinessMembership::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BusinessRole::Owner, $membership->role);
        $this->assertSame('Asif Remit', $membership->business->name);
        $this->assertSame('FJ', $membership->business->country_code);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register', 'actor_user_id' => $user->id]);
    }

    public function test_the_code_is_emailed_and_only_its_hash_is_stored(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        Mail::assertSent(OtpMail::class, 1);
        $code = $this->lastOtp();
        $this->assertSame(1, preg_match('/^\d{6}$/', $code));
        $this->assertTrue($this->otpMails()->first()->hasTo('asif@example.com'));

        $row = OtpCode::firstOrFail();
        $this->assertSame(64, strlen($row->code_hash));
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(OtpCode::PURPOSE_VERIFY_EMAIL, $row->purpose);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
    }

    public function test_email_is_trimmed_and_lower_cased(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => '  Asif@Example.COM ']))->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'asif@example.com']);
    }

    public function test_a_duplicate_email_is_rejected_even_with_different_letter_case(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'asif@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'ASIF@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::count());
        Mail::assertNothingSent();
    }

    public function test_invalid_input_is_rejected_and_creates_nothing(): void
    {
        Mail::fake();

        $cases = [
            'name'           => ['name' => ''],
            'email'          => ['email' => 'not-an-email'],
            'password'       => ['password' => 'short1', 'password_confirmation' => 'short1'],
            'business_name'  => ['business_name' => ''],
            'country_code'   => ['country_code' => 'FJI'],
            'timezone'       => ['timezone' => 'Mars/Phobos'],
            'business_phone' => ['business_phone' => 'abc'],
        ];

        foreach ($cases as $field => $override) {
            $this->postJson('/api/v1/auth/register', $this->registerPayload($override))
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors([$field]);
        }

        // a password without a number, and a confirmation that does not match
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['password_confirmation' => 'Different123']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertSame(0, User::count());
        $this->assertDatabaseCount('businesses', 0);
        Mail::assertNothingSent();
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(
                '/api/v1/auth/register',
                $this->registerPayload([
                    'email' => "user{$i}@example.com",
                    'android_id' => sprintf('%016x', $i),
                ])
            )->assertStatus(201);
        }

        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'user11@example.com']))
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_disposable_email_is_rejected_without_creating_an_account(): void
    {
        Mail::fake();

        $this->postJson(
            '/api/v1/auth/register',
            $this->registerPayload([
                'email' => 'test@mailinator.com',
            ])
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('otp_codes', 0);

        Mail::assertNothingSent();
    }

    public function test_regular_email_registration_remains_allowed(): void
    {
        Mail::fake();

        $this->postJson(
            '/api/v1/auth/register',
            $this->registerPayload([
                'email' => 'newuser@gmail.com',
            ])
        )
            ->assertStatus(201)
            ->assertJsonPath(
                'data.email_verification_required',
                true
            );

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
        ]);

        Mail::assertSent(OtpMail::class, 1);
    }

    public function test_five_registrations_from_same_device_are_allowed_but_sixth_is_rejected(): void
    {
        Mail::fake();

        $deviceId = 'a1b2c3d4e5f60718';

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(
                '/api/v1/auth/register',
                $this->registerPayload([
                    'email' => "deviceuser{$i}@example.com",
                    'android_id' => $deviceId,
                ])
            )->assertStatus(201);
        }

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('businesses', 5);
        $this->assertDatabaseCount('business_memberships', 5);
        $this->assertDatabaseCount('device_registrations', 5);

        $this->postJson(
            '/api/v1/auth/register',
            $this->registerPayload([
                'email'      => 'deviceuser6@example.com',
                'android_id' => $deviceId,
            ])
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'DEVICE_REGISTRATION_LIMIT');

        // Rejected registration must not create partial records.
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('businesses', 5);
        $this->assertDatabaseCount('business_memberships', 5);
        $this->assertDatabaseCount('device_registrations', 5);
        $this->assertDatabaseCount('registration_devices', 1);

        $this->assertDatabaseMissing('users', [
            'email' => 'deviceuser6@example.com',
        ]);
    }

    public function test_different_android_devices_have_independent_registration_limits(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 6; $i++) {
            $deviceId = $i <= 5
            ? 'a1b2c3d4e5f60718'
            : 'b1c2d3e4f5061728';

            $this->postJson(
                '/api/v1/auth/register',
                $this->registerPayload([
                    'email' => "separate{$i}@example.com",
                    'android_id' => $deviceId,
                ])
            )->assertStatus(201);
        }

        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('device_registrations', 6);
        $this->assertDatabaseCount('registration_devices', 2);
    }

    public function test_existing_verified_account_can_log_in_without_android_id(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Secret123',
        ])
            ->assertOk()
            ->assertJsonPath('code', 'OK');

        $this->assertDatabaseCount('device_registrations', 0);
    }

}
