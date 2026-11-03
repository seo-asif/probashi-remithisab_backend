<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\ManagerSubscription;
use App\Models\PlatformAdmin;
use App\Models\SenderSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubscriptionsDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['status'=>AccountStatus::Active, 'email_verified_at'=>now()]);
        PlatformAdmin::create(['user_id'=>$user->id,'status'=>AccountStatus::Active]);
        return $user;
    }

    public function test_guest_cannot_view_subscriptions(): void
    {
        $this->getJson('/admin/subscriptions')->assertUnauthorized();
    }

    public function test_non_admin_cannot_view_subscriptions(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/subscriptions')->assertForbidden();
    }

    public function test_admin_without_mfa_cannot_view_subscriptions(): void
    {
        $this->actingAs($this->admin())->get('/admin/subscriptions')->assertForbidden();
    }

    public function test_verified_admin_can_view_sender_records(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name'=>'Sender Example']);
        SenderSubscription::create(['user_id'=>$user->id,'tier'=>'plus','access_source'=>'manual','access_starts_at'=>now()->subDay(),'access_ends_at'=>now()->addMonth()]);
        $this->actingAs($admin)->withSession(['admin_mfa.user_id'=>$admin->id,'admin_mfa.last_activity'=>time()])
            ->get('/admin/subscriptions?type=sender&access=active')->assertOk()->assertSee('Sender Example')->assertSee('Active paid');
    }

    public function test_verified_admin_can_view_manager_tab(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->withSession(['admin_mfa.user_id'=>$admin->id,'admin_mfa.last_activity'=>time()])
            ->get('/admin/subscriptions?type=manager')->assertOk()->assertSee('Manager Pro');
    }
}