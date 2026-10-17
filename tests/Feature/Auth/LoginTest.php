<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private function login(array $override = [])
    {
        return $this->postJson('/api/v1/auth/login', $override + ['email' => 'owner@example.com', 'password' => 'Secret123']);
    }

    public function test_a_verified_owner_can_log_in_and_the_token_works_and_expires(): void
    {
        $user = $this->makeUser();

        $response = $this->login(['email' => ' Owner@Example.com ', 'device_name' => 'Pixel 7'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'email'], 'business' => ['id', 'name', 'timezone']]]);

        $token = PersonalAccessToken::firstOrFail();
        $this->assertSame('Pixel 7', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_user_id' => $user->id]);

        $this->asToken($response->json('data.token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'owner@example.com');
    }

    public function test_the_token_lifetime_comes_from_configuration(): void
    {
        config(['remit.token_ttl_minutes' => 10]);
        $this->makeUser();

        $this->login()->assertOk();

        $this->assertTrue(PersonalAccessToken::firstOrFail()->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));
    }

    public function test_a_wrong_password_and_an_unknown_email_get_the_same_answer(): void
    {
        $this->makeUser();

        $wrong   = $this->login(['password' => 'WrongPass123']);
        $unknown = $this->login(['email' => 'nobody@example.com']);

        $wrong->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_the_failed_login_log_never_contains_the_email_or_password(): void
    {
        $this->makeUser();
        $this->login(['email' => 'nobody@example.com', 'password' => 'TopSecret999'])->assertStatus(401);

        $row = \App\Models\AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $json = json_encode($row->toArray());

        $this->assertStringNotContainsString('nobody@example.com', $json);
        $this->assertStringNotContainsString('TopSecret999', $json);
    }

    public function test_an_unverified_email_cannot_log_in_and_gets_no_token(): void
    {
        $this->makeUnverifiedUser();

        $this->login()->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED')->assertJsonPath('data.email', 'owner@example.com');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->login()->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_user_without_a_business_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'password' => 'Secret123']);

        $this->login()->assertStatus(403)->assertJsonPath('code', 'NO_BUSINESS');
    }

    public function test_a_google_only_account_cannot_log_in_with_a_password(): void
    {
        $this->makeUser(['password' => null]);

        $this->login()->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_is_limited_to_five_tries_a_minute(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->login(['password' => 'WrongPass123'])->assertStatus(401);
        }

        $this->login()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
