<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_and_uses_the_standard_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.api_version', 'v1');
    }
}
