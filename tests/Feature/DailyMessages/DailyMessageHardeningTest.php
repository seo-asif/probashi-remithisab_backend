<?php

namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\AuditLog;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageHardeningTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private const URL = '/api/v1/platform/daily-messages';

    private function loginAdmin(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, BusinessRole::ReadOnlyStaff);
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        PlatformAdmin::create(['user_id' => $user->id, 'status' => 'active']);
        Sanctum::actingAs($user);
    }

    public function test_create_update_delete_are_audited_without_message_contents(): void
    {
        $this->loginAdmin();
        $id = $this->postJson(self::URL, [
            'code' => 'SEC001', 'message_bn' => 'Secret message', 'status' => 'draft',
        ])->assertCreated()->json('data.uuid');
        $this->patchJson(self::URL.'/'.$id, ['category' => 'savings'])->assertOk();
        $this->deleteJson(self::URL.'/'.$id)->assertOk();

        $messageId = AuditLog::where('action', 'platform.daily_message.created')->value('target_id');
        $this->assertNotNull($messageId);
        foreach (['created', 'updated', 'deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'platform.daily_message.'.$action,
                'target_id' => $messageId,
            ]);
        }
        $this->assertStringNotContainsString('Secret message', AuditLog::where('target_id', $messageId)->get()->toJson());
    }

    public function test_bulk_status_and_import_are_audited(): void
    {
        $this->loginAdmin();
        $message = DailyMessage::create(['code' => 'SEC002', 'message_bn' => 'Hello', 'status' => 'draft']);
        $this->postJson(self::URL.'/bulk-status', ['ids' => [$message->uuid], 'status' => 'published'])->assertOk();
        $file = UploadedFile::fake()->createWithContent('quotes.csv',
            "code,message_bn,message_en,status,category\nSEC003,Hello,Hello,published,savings\n");
        $this->post(self::URL.'/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.daily_message.bulk_status']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.daily_message.imported']);
    }

    public function test_invalid_csv_does_not_write_messages_or_audit_events(): void
    {
        $this->loginAdmin();
        $file = UploadedFile::fake()->createWithContent('quotes.csv',
            "code,message_bn,message_en,status,category\nSEC004,Valid,Valid,published,savings\nSEC005,Invalid,Invalid,published,invalid_category\n");
        $this->post(self::URL.'/import', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseMissing('daily_messages', ['code' => 'SEC004']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform.daily_message.imported']);
    }

    public function test_guest_cannot_access_admin_mutations(): void
    {
        $this->postJson(self::URL, ['code' => 'NO', 'message_bn' => 'No', 'status' => 'published'])->assertUnauthorized();
        $this->postJson(self::URL.'/bulk-status', ['ids' => ['11111111-1111-4111-8111-111111111111'], 'status' => 'published'])->assertUnauthorized();
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
