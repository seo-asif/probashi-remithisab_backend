<?php

namespace Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupInputTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const ENDPOINTS = [
        '/api/v1/account/backup/sender/preview',
        '/api/v1/account/backup/sender/restore',
    ];

    public function test_backup_endpoints_reject_malformed_json(): void
    {
        $this->actAs($this->makeBusiness());
        foreach (self::ENDPOINTS as $endpoint) {
            $this->call('POST', $endpoint, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{"format":')
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_backup_endpoints_reject_oversize_json_without_writing(): void
    {
        $this->actAs($this->makeBusiness());
        $oversized = str_repeat(' ', \App\Services\Account\SenderBackupInput::MAX_BYTES + 1);
        foreach (self::ENDPOINTS as $endpoint) {
            $this->call('POST', $endpoint, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], $oversized)
                ->assertStatus(413)
                ->assertJsonPath('code', 'BACKUP_TOO_LARGE');
        }
        $this->assertDatabaseCount('sender_remittances', 0);
    }

    public function test_backup_endpoints_reject_non_json_content_type(): void
    {
        $this->actAs($this->makeBusiness());
        foreach (self::ENDPOINTS as $endpoint) {
            $this->call('POST', $endpoint, [], [], [], [
                'CONTENT_TYPE' => 'text/plain',
            ], '{}')
                ->assertStatus(415)
                ->assertJsonPath('code', 'BACKUP_JSON_REQUIRED');
        }
    }

    public function test_backup_endpoints_reject_json_array_root(): void
    {
        $this->actAs($this->makeBusiness());
        foreach (self::ENDPOINTS as $endpoint) {
            $this->call('POST', $endpoint, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '[]')
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }
    }
}
