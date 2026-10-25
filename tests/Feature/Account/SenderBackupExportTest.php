<?php

namespace Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class SenderBackupExportTest extends TestCase
{
    use RefreshDatabase, Phase3Fixtures;

    private const URL = '/api/v1/account/backup/sender';

    public function test_authentication_is_required(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_export_contains_only_the_authenticated_users_sender_records(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $this->recipient(['name' => 'First Family']);
        $this->senderRemittance();
        $this->actAs($business);
        $this->recipient(['name' => 'Other Family']);
        $this->senderRemittance();

        $this->login($first);
        $response = $this->get(self::URL)->assertOk();
        $response->assertJsonPath('format', 'probashi-remithisab-sender')
            ->assertJsonPath('schema_version', 1)
            ->assertJsonCount(1, 'datasets.recipients')
            ->assertJsonCount(1, 'datasets.remittances')
            ->assertJsonPath('datasets.recipients.0.name', 'First Family');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringNotContainsString('Other Family', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('owner_user_id', $response->getContent());
        $this->assertStringNotContainsString('business_id', $response->getContent());
    }

    public function test_export_does_not_change_data(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->recipient();
        $this->get(self::URL)->assertOk();
        $this->get(self::URL)->assertOk()->assertJsonCount(1, 'datasets.recipients');
    }
}
