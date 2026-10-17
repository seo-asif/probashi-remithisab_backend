<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthHelpers;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    public function test_logout_removes_only_this_devices_token(): void
    {
        $user  = $this->makeUser();
        $phone = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');

        $this->asToken($phone)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('code', 'OK');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'tablet']);
        $this->asToken($phone)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_logout_all_removes_every_token(): void
    {
        $user  = $this->makeUser();
        $phone = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');

        $this->asToken($phone)->postJson('/api/v1/auth/logout-all')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout_all']);
    }

    public function test_logout_needs_a_login(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/auth/logout-all')->assertStatus(401);
    }
}
