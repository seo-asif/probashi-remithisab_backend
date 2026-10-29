<?php

namespace Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    public function test_unauthenticated_profile_is_rejected(): void
    {
        $this->getJson('/api/v1/account/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/account/profile', ['name' => 'Changed'])->assertUnauthorized();
    }

    public function test_profile_name_and_whatsapp_are_saved_without_changing_email(): void
    {
        $user = $this->actAs($this->makeBusiness());

        $this->patchJson('/api/v1/account/profile', [
            'name' => 'Updated Name',
            'whatsapp' => '+679 1234567',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.whatsapp', '+679 1234567');

        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->assertSame('+679 1234567', $user->fresh()->whatsapp);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_email_cannot_be_changed_without_verification(): void
    {
        $user = $this->actAs($this->makeBusiness());

        $this->patchJson('/api/v1/account/profile', [
            'email' => 'unverified-new@example.com',
        ])->assertUnprocessable();

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_invalid_whatsapp_is_rejected(): void
    {
        $user = $this->actAs($this->makeBusiness());

        $this->patchJson('/api/v1/account/profile', ['whatsapp' => '<script>'])->assertUnprocessable();
    }
}
