<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function authorized(): static
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status'  => AccountStatus::Active,
        ]);

        return $this->actingAs($user)->withSession([
            'admin_mfa.user_id'       => $user->id,
            'admin_mfa.last_activity' => time(),
        ]);
    }

    public function test_guest_cannot_access_payments(): void
    {
        $this->getJson('/admin/payments')->assertUnauthorized();
    }

    public function test_normal_user_cannot_access_payments(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/payments')
            ->assertForbidden();
    }

    public function test_admin_without_mfa_cannot_access_payments(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create([
            'status'            => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status'  => AccountStatus::Active,
        ]);

        $this->actingAs($user)
            ->get('/admin/payments')
            ->assertForbidden();
    }

    public function test_authorized_admin_can_open_payment_directory(): void
    {
        $this->authorized()->get('/admin/payments')->assertOk()->assertSee('Payment requests');
    }

    public function test_payment_details_missing_id_returns_not_found(): void
    {
        $this->authorized()->get('/admin/payments/999999999')->assertNotFound();
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->authorized()->get('/admin/payments?status=not_real')->assertSessionHasErrors('status');
    }

    public function test_guest_is_redirected_to_admin_login_in_browser(): void
    {
        $this->get('/admin/payments')
            ->assertRedirect(route('admin.login'));
    }
}
