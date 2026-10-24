<?php

namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageAdminListingTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private const URL = '/api/v1/platform/daily-messages';

    private function admin(): void
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business, BusinessRole::ReadOnlyStaff);
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        PlatformAdmin::create(['user_id' => $user->id, 'status' => 'active']);
        Sanctum::actingAs($user);
    }

    public function test_summary_counts_and_admin_only_access(): void
    {
        $this->getJson(self::URL.'/summary')->assertUnauthorized();
        $this->admin();
        DailyMessage::create(['code' => 'A', 'message_bn' => 'One', 'status' => 'draft']);
        DailyMessage::create(['code' => 'B', 'message_bn' => 'Two', 'status' => 'published']);
        $this->getJson(self::URL.'/summary')->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.draft', 1)
            ->assertJsonPath('data.published', 1);
    }

    public function test_listing_filters_paginates_and_validates(): void
    {
        $this->admin();
        DailyMessage::create(['code' => 'A', 'message_bn' => 'First', 'status' => 'published', 'sort_order' => 20]);
        DailyMessage::create(['code' => 'B', 'message_bn' => 'Second', 'status' => 'draft', 'sort_order' => 10]);
        $this->getJson(self::URL.'?per_page=1')->assertOk()
            ->assertJsonPath('data.data.0.code', 'B');
        $this->getJson(self::URL.'?status=published&q=First')->assertOk()
            ->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL.'?per_page=101')->assertUnprocessable();
        $this->getJson(self::URL.'?status=invalid')->assertUnprocessable();
    }

    public function test_patch_allows_partial_changes_and_rejects_empty_payload(): void
    {
        $this->admin();
        $message = DailyMessage::create(['code' => 'A', 'message_bn' => 'Original', 'status' => 'draft']);
        $this->patchJson(self::URL.'/'.$message->uuid, ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.message_bn', 'Original');
        $this->patchJson(self::URL.'/'.$message->uuid, [])->assertUnprocessable();
        $this->assertDatabaseHas('daily_messages', ['code' => 'A', 'status' => 'published']);
    }
}
