<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DailyMessagesToolsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        $admin = PlatformAdmin::query()->create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
            'admin_mfa.session_version' => (int) $admin->session_version,
        ]);
        return $user;
    }

    private function message(string $code = 'existing_1'): DailyMessage
    {
        return DailyMessage::query()->create([
            'code' => $code,
            'message_bn' => 'পুরোনো বার্তা',
            'message_en' => 'Existing message',
            'category' => 'family',
            'status' => 'draft',
            'sort_order' => 1,
        ]);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('messages.csv', $content);
    }

    public function test_guests_cannot_access_tools_or_analytics(): void
    {
        $this->getJson('/admin/daily-messages/tools')->assertUnauthorized();
        $this->getJson('/admin/daily-messages/analytics')->assertUnauthorized();
        $this->postJson('/admin/daily-messages/bulk-status', [])->assertUnauthorized();
        $this->getJson('/admin/daily-messages/export')->assertUnauthorized();
    }

    public function test_regular_users_and_admins_without_mfa_cannot_access_tools(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/daily-messages/tools')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/daily-messages/analytics')->assertForbidden();
        $user = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::query()->create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->post('/admin/daily-messages/import/preview', [])->assertForbidden();
    }

    public function test_admin_can_preview_and_import_creating_and_updating_messages(): void
    {
        $admin = $this->admin();
        $this->message();
        $content = "code,message_bn,message_en,status,category\nexisting_1,পরিবর্তিত বার্তা,Changed,published,family\nnew_2,নতুন বার্তা,New,draft,health\n";
        $this->post('/admin/daily-messages/import/preview', ['file' => $this->csv($content)])
            ->assertRedirect(route('admin.daily-messages.tools'));
        $this->get('/admin/daily-messages/tools')->assertOk()->assertSee('Import preview')
            ->assertSee('existing_1')->assertSee('new_2');
        $this->assertDatabaseCount('daily_messages', 1);
        $preview = session('daily_messages.import_preview');
        $this->assertSame(1, $preview['create']);
        $this->assertSame(1, $preview['update']);
        $this->post('/admin/daily-messages/import', [
            'preview_token' => $preview['token'], 'confirm' => '1',
        ])->assertRedirect(route('admin.daily-messages'));
        $this->assertDatabaseHas('daily_messages', ['code' => 'existing_1', 'status' => 'published', 'message_en' => 'Changed']);
        $this->assertDatabaseHas('daily_messages', ['code' => 'new_2', 'category' => 'health']);
        $this->assertDatabaseCount('daily_messages', 2);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'platform.daily_message.imported']);
        $this->assertNull(session('daily_messages.import_preview'));
    }

    public function test_invalid_csv_does_not_mutate_messages(): void
    {
        $this->admin();
        $this->post('/admin/daily-messages/import/preview', [
            'file' => $this->csv("code,message_bn,message_en,status\nbad,বার্তা,,invalid\n"),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('daily_messages', 0);
    }

    public function test_import_requires_matching_preview_and_cannot_replay(): void
    {
        $this->admin();
        $this->post('/admin/daily-messages/import', [
            'preview_token' => 'missing', 'confirm' => '1',
        ])->assertSessionHasErrors('file');
        $this->post('/admin/daily-messages/import/preview', [
            'file' => $this->csv("code,message_bn,message_en,status\na,বার্তা,,draft\n"),
        ])->assertRedirect();
        $token = session('daily_messages.import_preview.token');
        $this->post('/admin/daily-messages/import', ['preview_token' => $token, 'confirm' => '1'])->assertRedirect();
        $this->post('/admin/daily-messages/import', ['preview_token' => $token, 'confirm' => '1'])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('daily_messages', 1);
    }

    public function test_bulk_status_requires_all_ids_and_updates_atomically(): void
    {
        $admin = $this->admin();
        $first = $this->message('a');
        $second = $this->message('b');
        $this->post('/admin/daily-messages/bulk-status', [
            'ids' => [$first->id, 999999], 'status' => 'published',
        ])->assertSessionHasErrors('ids');
        $this->assertDatabaseHas('daily_messages', ['id' => $first->id, 'status' => 'draft']);
        $this->post('/admin/daily-messages/bulk-status', [
            'ids' => [$first->id, $second->id], 'status' => 'published',
        ])->assertRedirect(route('admin.daily-messages'));
        $this->assertDatabaseHas('daily_messages', ['id' => $first->id, 'status' => 'published']);
        $this->assertDatabaseHas('daily_messages', ['id' => $second->id, 'status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'platform.daily_message.bulk_status']);
    }

    public function test_csv_export_has_expected_columns_and_private_cache_headers(): void
    {
        $this->admin();
        $this->message();
        $response = $this->get('/admin/daily-messages/export')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $contents = $response->streamedContent();
        $this->assertStringContainsString('code,message_bn,message_en,status,category', $contents);
        $this->assertStringContainsString('existing_1', $contents);
    }

    public function test_analytics_aggregates_likes_and_filters_category(): void
    {
        $this->admin();
        $family = $this->message('family_message');
        $other = $this->message('health_message');
        $other->update(['category' => 'health']);
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            DB::table('daily_message_likes')->insert([
                'daily_message_id' => $family->id, 'user_id' => $user->id, 'created_at' => now(),
            ]);
        }
        DB::table('daily_message_likes')->insert([
            'daily_message_id' => $other->id, 'user_id' => $users[0]->id, 'created_at' => now(),
        ]);
        $this->get('/admin/daily-messages/analytics')->assertOk()->assertSee('Message analytics')
            ->assertSee('family_message')->assertSee('health_message');
        $this->get('/admin/daily-messages/analytics?category=health')->assertOk()
            ->assertSee('health_message')->assertDontSee('family_message');
    }
}
