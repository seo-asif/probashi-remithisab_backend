<?php

namespace Tests\Feature\Account;

use App\Enums\BusinessRole;
use Illuminate\Support\Facades\DB;
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
        $this->actAs($business, BusinessRole::Operator);
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

    public function test_records_from_another_business_are_excluded(): void
    {
        $firstBusiness = $this->makeBusiness();
        $firstUser = $this->actAs($firstBusiness);
        $this->recipient(['name' => 'First Business']);

        $secondBusiness = $this->makeBusiness();
        $this->actAs($secondBusiness);
        $this->recipient(['name' => 'Second Business']);

        $this->login($firstUser);
        $response = $this->get(self::URL)->assertOk()
            ->assertJsonCount(1, 'datasets.recipients')
            ->assertJsonPath('datasets.recipients.0.name', 'First Business');

        $this->assertStringNotContainsString('Second Business', $response->getContent());
    }

    public function test_soft_deleted_recipient_is_preserved_for_future_restore(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $recipient = $this->recipient(['name' => 'Archived Family']);
        $this->deleteJson('/api/v1/sender-recipients/' . $recipient['id'])->assertSuccessful();

        $this->get(self::URL)->assertOk()
            ->assertJsonCount(1, 'datasets.recipients')
            ->assertJsonPath('datasets.recipients.0.name', 'Archived Family');

        $this->assertNotNull(
            DB::table('sender_recipients')->where('uuid', $recipient['id'])->value('deleted_at')
        );
    }

    public function test_export_records_a_business_scoped_audit_event(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->get(self::URL)->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'business_id' => $business->id,
            'action' => 'account.sender_backup_exported',
        ]);
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
