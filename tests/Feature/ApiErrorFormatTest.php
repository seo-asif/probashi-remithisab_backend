<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProbeRoutes;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeRoutes::register();
    }

    public function test_unknown_route_returns_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND')->assertJsonPath('success', false);
    }

    public function test_wrong_method_returns_405(): void
    {
        $this->postJson('/api/v1/health')->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_validation_errors_use_the_standard_shape(): void
    {
        $this->postJson('/api/v1/_probe_public/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_server_errors_never_expose_internals(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_probe_public/boom')->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');

        $this->assertStringNotContainsString('secret database password', $response->getContent());
    }
}
