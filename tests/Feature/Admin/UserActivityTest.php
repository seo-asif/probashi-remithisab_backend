<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UserActivityTest extends TestCase
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

    public function test_guest_and_nonadmin_cannot_read_user_activity(): void
    {
        $this->getJson('/admin/user-activity')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get('/admin/user-activity')->assertForbidden();
    }

    public function test_admin_sees_read_only_activity_and_filters(): void
    {
        $this->admin();
        $user = User::factory()->create(['name' => 'Report Tester']);
        DB::table('daily_messages')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'code' => 'ua_test',
            'message_bn' => 'পরীক্ষা', 'status' => 'published', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = DB::table('daily_messages')->where('code', 'ua_test')->value('id');
        DB::table('daily_message_likes')->insert([
            'daily_message_id' => $messageId, 'user_id' => $user->id, 'created_at' => now(),
        ]);

        $this->get('/admin/user-activity?period=30&sort=likes')->assertOk()
            ->assertSee('Report Tester')->assertSee('Saved message likes');
        $this->get('/admin/user-activity?period=invalid')->assertSessionHasErrors('period');
        $this->get('/admin/user-activity?q=Report%20Tester')->assertOk()->assertSee('Report Tester');
    }
}
