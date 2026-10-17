<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\Google\GoogleAuthLibraryVerifier;
use App\Support\Auth\Google\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthHelpers;
use Tests\Support\FakeGoogleVerifier;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    private FakeGoogleVerifier $google;

    protected function setUp(): void
    {
        parent::setUp();

        $this->google = new FakeGoogleVerifier();
        $this->app->instance(GoogleIdTokenVerifier::class, $this->google);
    }

    private function claims(array $override = []): array
    {
        return $override + ['sub' => 'google-123', 'email' => 'new@example.com', 'email_verified' => true, 'name' => 'New Person'];
    }

    private function signIn()
    {
        return $this->postJson('/api/v1/auth/google', ['id_token' => 'any-token', 'device_name' => 'Pixel']);
    }

    public function test_a_new_google_user_gets_an_account_a_business_and_a_token(): void
    {
        $this->google->claims = $this->claims();

        $this->signIn()
            ->assertOk()
            ->assertJsonPath('data.is_new_account', true)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.business.name', "New Person's business")
            ->assertJsonPath('data.business.timezone', 'Pacific/Fiji')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-123']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.google_registered']);
    }

    public function test_the_same_google_account_logs_in_to_the_same_user_next_time(): void
    {
        $this->google->claims = $this->claims();

        $this->signIn()->assertOk()->assertJsonPath('data.is_new_account', true);
        $this->signIn()->assertOk()->assertJsonPath('data.is_new_account', false);

        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_an_existing_password_account_is_never_merged_automatically(): void
    {
        $this->makeUser(['email' => 'new@example.com']);
        $this->google->claims = $this->claims();

        $this->signIn()
            ->assertStatus(409)
            ->assertJsonPath('code', 'ACCOUNT_LINK_REQUIRED')
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        $this->google->claims = $this->claims(['email_verified' => false]);

        $this->signIn()->assertStatus(403)->assertJsonPath('code', 'GOOGLE_EMAIL_NOT_VERIFIED');
        $this->assertSame(0, User::count());
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $this->google->claims = null;

        $this->signIn()->assertStatus(401)->assertJsonPath('code', 'GOOGLE_TOKEN_INVALID');
        $this->assertSame(0, User::count());
    }

    public function test_claims_without_an_id_or_email_are_refused(): void
    {
        $this->google->claims = ['sub' => '', 'email' => 'x@example.com', 'email_verified' => true];
        $this->signIn()->assertStatus(401);

        $this->google->claims = ['sub' => 'abc', 'email' => '', 'email_verified' => true];
        $this->signIn()->assertStatus(401);
    }

    public function test_it_reports_a_configuration_error_when_google_is_not_configured(): void
    {
        config(['remit.google.client_ids' => []]);
        $this->app->bind(GoogleIdTokenVerifier::class, GoogleAuthLibraryVerifier::class);

        $this->signIn()->assertStatus(503)->assertJsonPath('code', 'CONFIGURATION_ERROR');
    }

    public function test_linking_needs_the_current_password_and_then_google_login_works(): void
    {
        $user = $this->makeUser();
        $this->google->claims = $this->claims(['email' => 'someone.else@gmail.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/google/link', ['id_token' => 'x', 'current_password' => 'Wrong12345'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseCount('social_accounts', 0);

        $this->postJson('/api/v1/auth/google/link', ['id_token' => 'x', 'current_password' => 'Secret123'])->assertOk();
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider_user_id' => 'google-123']);

        $this->app['auth']->forgetGuards();
        $this->signIn()->assertOk()->assertJsonPath('data.is_new_account', false)->assertJsonPath('data.user.email', 'owner@example.com');
    }

    public function test_a_google_account_linked_to_someone_else_cannot_be_linked_again(): void
    {
        $first = $this->makeUser();
        SocialAccount::create(['user_id' => $first->id, 'provider' => 'google', 'provider_user_id' => 'google-123', 'email' => 'a@example.com']);

        $second = \App\Models\User::factory()->create(['email' => 'second@example.com', 'password' => 'Secret123']);
        $this->google->claims = $this->claims();
        Sanctum::actingAs($second);

        $this->postJson('/api/v1/auth/google/link', ['id_token' => 'x', 'current_password' => 'Secret123'])
            ->assertStatus(409)->assertJsonPath('code', 'GOOGLE_ALREADY_LINKED');
    }

    public function test_unlinking_needs_the_password_and_is_refused_for_a_google_only_account(): void
    {
        $user = $this->makeUser();
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-123']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/auth/google', ['current_password' => 'Wrong12345'])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseCount('social_accounts', 1);

        $this->deleteJson('/api/v1/auth/google', ['current_password' => 'Secret123'])->assertOk();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_the_last_login_method_cannot_be_removed(): void
    {
        $googleOnly = User::factory()->create(['email' => 'g@example.com', 'password' => null]);
        SocialAccount::create(['user_id' => $googleOnly->id, 'provider' => 'google', 'provider_user_id' => 'google-999']);
        Sanctum::actingAs($googleOnly);

        $this->deleteJson('/api/v1/auth/google', ['current_password' => 'anything'])
            ->assertStatus(422)->assertJsonPath('code', 'CANNOT_UNLINK_LAST_LOGIN_METHOD');
        $this->assertDatabaseCount('social_accounts', 1);
    }
}
