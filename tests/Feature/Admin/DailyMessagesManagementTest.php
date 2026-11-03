<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyMessagesManagementTest extends TestCase
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

    private function payload(string $code = 'family_001'): array
    {
        return ['code' => $code, 'message_bn' => 'পরিবারের জন্য আজকের কথা', 'message_en' => 'A message for family', 'category' => 'family', 'status' => 'draft', 'sort_order' => 5];
    }

    public function test_guest_cannot_open_management_page(): void
    {
        $this->getJson('/admin/daily-messages')->assertUnauthorized();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/daily-messages')->assertForbidden();
    }

    public function test_admin_without_mfa_is_forbidden(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::query()->create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->get('/admin/daily-messages')->assertForbidden();
    }

    public function test_admin_can_create_and_list_message(): void
    {
        $user = $this->admin();
        $this->post('/admin/daily-messages', $this->payload())->assertRedirect(route('admin.daily-messages'));
        $this->assertDatabaseHas('daily_messages', ['code' => 'family_001', 'status' => 'draft', 'category' => 'family']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $user->id, 'action' => 'platform.daily_message.created']);
        $this->get('/admin/daily-messages?status=draft&category=family&q=family_001')->assertOk()->assertSee('family_001');
        $this->get('/admin/daily-messages?status=published')->assertOk()->assertDontSee('family_001');
    }

    public function test_admin_can_edit_and_publish_existing_message(): void
    {
        $this->admin();
        $message = DailyMessage::query()->create($this->payload());
        $this->get('/admin/daily-messages/'.$message->id.'/edit')->assertOk()->assertSee('family_001');
        $new = $this->payload('family_002');
        $new['status'] = 'published';
        $this->post('/admin/daily-messages/'.$message->id, $new)->assertRedirect(route('admin.daily-messages'));
        $this->assertDatabaseHas('daily_messages', ['id' => $message->id, 'code' => 'family_002', 'status' => 'published']);
        $this->post('/admin/daily-messages/'.$message->id.'/status', ['status' => 'draft'])->assertRedirect(route('admin.daily-messages'));
        $this->assertDatabaseHas('daily_messages', ['id' => $message->id, 'status' => 'draft']);
    }

    public function test_invalid_category_and_duplicate_code_are_rejected(): void
    {
        $this->admin();
        DailyMessage::query()->create($this->payload());
        $this->post('/admin/daily-messages', $this->payload())->assertSessionHasErrors('code');
        $payload = $this->payload('other_001');
        $payload['category'] = 'invalid';
        $this->post('/admin/daily-messages', $payload)->assertSessionHasErrors('category');
        $this->assertDatabaseCount('daily_messages', 1);
    }

    public function test_guest_cannot_publish_and_unverified_admin_cannot_create(): void
    {
        $message = DailyMessage::query()->create($this->payload());
        $this->postJson('/admin/daily-messages/'.$message->id.'/status', ['status' => 'published'])->assertUnauthorized();
        $user = User::factory()->create(['status' => AccountStatus::Active, 'email_verified_at' => now()]);
        PlatformAdmin::query()->create(['user_id' => $user->id, 'status' => AccountStatus::Active]);
        $this->actingAs($user)->post('/admin/daily-messages', $this->payload('blocked_001'))->assertForbidden();
        $this->assertDatabaseHas('daily_messages', ['id' => $message->id, 'status' => 'draft']);
    }
}
