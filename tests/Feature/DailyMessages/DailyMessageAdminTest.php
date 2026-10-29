<?php

namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageAdminTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private const URL = '/api/v1/platform/daily-messages';

    public function test_tenant_owner_cannot_read_write_or_import(): void
    {
        [, $owner] = $this->makeAuthorizedMember(BusinessRole::Owner);
        Sanctum::actingAs($owner);

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['code' => 'Q1', 'message_bn' => 'Test', 'status' => 'published'])->assertForbidden();
        $this->post(self::URL.'/import', ['file' => UploadedFile::fake()->createWithContent('quotes.csv', 'code,message_bn,message_en,status')])->assertForbidden();
        $this->get(self::URL.'/export')->assertForbidden();
    }

    public function test_platform_admin_can_manage_messages_and_sync_removes_unpublished(): void
    {
        $this->actingAsPlatformAdmin();
        $created = $this->postJson(self::URL, ['code' => 'Q1', 'message_bn' => 'প্রতিদিন সঞ্চয়', 'status' => 'published'])
            ->assertCreated()->json('data');
        $this->getJson(self::URL)->assertOk();
        $first = $this->getJson('/api/v1/daily-messages/sync')->assertOk()->json('data');
        $this->patchJson(self::URL.'/'.$created['uuid'], [
            'code' => 'Q1', 'message_bn' => 'প্রতিদিন সঞ্চয়', 'status' => 'draft',
        ])->assertOk();
        $this->getJson('/api/v1/daily-messages/sync?version='.$first['version'])
            ->assertOk()->assertJsonPath('data.unchanged', false)
            ->assertJsonCount(0, 'data.messages');
        $this->deleteJson(self::URL.'/'.$created['uuid'])->assertOk();
        $this->assertDatabaseMissing('daily_messages', ['code' => 'Q1']);
    }

    public function test_csv_import_upserts_and_rejects_duplicate_codes_atomically(): void
    {
        $this->actingAsPlatformAdmin();
        $header = "code,message_bn,message_en,status\n";
        $file = UploadedFile::fake()->createWithContent('quotes.csv', $header."Q1,সঞ্চয় করুন,Save,published\nQ2,পরিকল্পনা করুন,Plan,draft\n");
        $this->post(self::URL.'/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.imported', 2);
        $this->assertDatabaseCount('daily_messages', 2);

        $updated = UploadedFile::fake()->createWithContent('quotes.csv', $header."Q1,নতুন বার্তা,New,published\n");
        $this->post(self::URL.'/import', ['file' => $updated], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('daily_messages', 2);
        $this->assertDatabaseHas('daily_messages', ['code' => 'Q1', 'message_bn' => 'নতুন বার্তা']);

        $duplicate = UploadedFile::fake()->createWithContent('quotes.csv', $header."Q3,One,,published\nQ3,Two,,published\n");
        $this->post(self::URL.'/import', ['file' => $duplicate], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseMissing('daily_messages', ['code' => 'Q3']);
    }

    public function test_invalid_csv_does_not_partially_write(): void
    {
        $this->actingAsPlatformAdmin();
        $file = UploadedFile::fake()->createWithContent('quotes.csv',
            "code,message_bn,message_en,status\nQ1,Valid,,published\nQ2,Invalid,,unknown\n");
        $this->post(self::URL.'/import', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('daily_messages', 0);
    }
    private function makeAuthorizedMember(BusinessRole $role): array
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, $role);
        $user->forceFill([
            'status' => 'active',
            'email_verified_at' => now(),
        ])->save();

        return [$business->refresh(), $user->refresh()];
    }

    private function actingAsPlatformAdmin(): PlatformAdmin
    {
        [, $user] = $this->makeAuthorizedMember(BusinessRole::ReadOnlyStaff);
        $admin = PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $admin;
    }

}
