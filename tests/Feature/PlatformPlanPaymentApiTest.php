<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\PlanPaymentStatus;
use App\Models\BusinessSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PlatformPlanPaymentApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_platform_payment_routes(): void
    {
        $this->getJson('/api/v1/platform/plan-payments')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_business_owner_cannot_approve_own_payment(): void
    {
        [$business, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'OWNER00001',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/approve"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        $this->assertFalse(
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->where('tier', 'unlimited')
                ->exists(),
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            PlanPaymentRequest::query()
                ->where('uuid', $paymentId)
                ->firstOrFail()
                ->status,
        );
    }

    public function test_business_administrator_cannot_approve_payment(): void
    {
        [$business, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 3,
            'trx_id'         => 'ADMIN00001',
            'payer_number'   => '01812345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        $administrator = $this->makeMember(
            $business,
            BusinessRole::Administrator,
        );

        $this->activateUser($administrator);

        Sanctum::actingAs($administrator);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/approve"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        $this->assertFalse(
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->where('tier', 'unlimited')
                ->exists(),
        );
    }

    public function test_platform_admin_can_list_payment_requests(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 6,
            'trx_id'         => 'LIST000001',
            'payer_number'   => '01912345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $this->makePlatformAdmin($adminUser);

        Sanctum::actingAs($adminUser);

        $response = $this
            ->getJson('/api/v1/platform/plan-payments')
            ->assertOk()
            ->assertJsonPath('success', true);

        $items = collect(
            $response->json('data.items')
        );

        $payment = $items->firstWhere(
            'id',
            $paymentId,
        );

        $this->assertNotNull($payment);

        $this->assertSame(
            'unlimited',
            $payment['product'],
        );

        $this->assertSame(
            6,
            $payment['package_months'],
        );

        $this->assertSame(
            2790,
            $payment['amount_bdt'],
        );

        $this->assertSame(
            'LIST000001',
            $payment['trx_id'],
        );

        $this->assertSame(
            '01912345678',
            $payment['payer_number'],
        );

        $this->assertSame(
            'pending',
            $payment['status'],
        );
    }

    public function test_platform_admin_can_approve_payment_and_activate_unlimited(): void
    {
        [$business, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 12,
            'trx_id'         => 'APPROVE001',
            'payer_number'   => '01612345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $platformAdmin = $this->makePlatformAdmin(
            $adminUser,
        );

        Sanctum::actingAs($adminUser);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/approve"
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.status',
                'approved',
            )
            ->assertJsonPath(
                'data.amount_bdt',
                5200,
            );

        $payment = PlanPaymentRequest::query()
            ->where('uuid', $paymentId)
            ->firstOrFail();

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $payment->status,
        );

        $this->assertSame(
            $platformAdmin->id,
            $payment->decided_by_platform_admin_id,
        );

        $this->assertNotNull(
            $payment->decided_at,
        );

        $this->assertNotNull(
            $payment->applied_at,
        );

        $subscription = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            'unlimited',
            $subscription->tier,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );
    }

    public function test_approving_same_payment_twice_through_http_does_not_extend_twice(): void
    {
        [$business, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'RETRY00001',
            'payer_number'   => '01512345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $this->makePlatformAdmin($adminUser);

        Sanctum::actingAs($adminUser);

        $url =
            "/api/v1/platform/plan-payments/{$paymentId}/approve";

        $this->postJson($url)
            ->assertOk();

        $firstExpiry = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail()
            ->access_ends_at;

        $this->postJson($url)
            ->assertOk();

        $secondExpiry = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail()
            ->access_ends_at;

        $this->assertTrue(
            $firstExpiry->equalTo($secondExpiry),
        );
    }

    public function test_platform_admin_can_reject_payment_without_activating_unlimited(): void
    {
        [$business, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'REJECT0001',
            'payer_number'   => '01312345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $this->makePlatformAdmin($adminUser);

        Sanctum::actingAs($adminUser);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/reject",
            [
                'reason' => 'TrxID not found in bKash.',
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'rejected',
            )
            ->assertJsonPath(
                'data.rejection_reason',
                'TrxID not found in bKash.',
            );

        $payment = PlanPaymentRequest::query()
            ->where('uuid', $paymentId)
            ->firstOrFail();

        $this->assertSame(
            PlanPaymentStatus::Rejected,
            $payment->status,
        );

        $this->assertNull(
            $payment->applied_at,
        );

        $this->assertFalse(
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->where('tier', 'unlimited')
                ->exists(),
        );
    }

    public function test_rejection_requires_reason(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'REASON0001',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $this->makePlatformAdmin($adminUser);

        Sanctum::actingAs($adminUser);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/reject",
            [],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            PlanPaymentRequest::query()
                ->where('uuid', $paymentId)
                ->firstOrFail()
                ->status,
        );
    }

    public function test_numeric_database_id_is_not_accepted_as_public_payment_id(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'UUID000001',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $payment = PlanPaymentRequest::query()->sole();

        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $this->makePlatformAdmin($adminUser);

        Sanctum::actingAs($adminUser);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$payment->id}/approve"
        )->assertNotFound();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );
    }

    public function test_client_cannot_spoof_platform_admin_when_approving_payment(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'SPOOF00001',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        /*
         * Create another Platform Admin whose ID the attacker
         * will try to inject into the approval request.
         */
        [, $otherAdminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $otherAdmin = $this->makePlatformAdmin(
            $otherAdminUser,
        );

        /*
         * This is the real authenticated Platform Admin.
         */
        [, $realAdminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $realAdmin = $this->makePlatformAdmin(
            $realAdminUser,
        );

        Sanctum::actingAs($realAdminUser);

        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/approve",
            [
                // Malicious/untrusted fields.
                'decided_by_platform_admin_id' => $otherAdmin->id,
                'status'                       => 'rejected',
                'applied_at'                   => now()->addYears(10)->toISOString(),
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'approved',
            );

        $payment = PlanPaymentRequest::query()
            ->where('uuid', $paymentId)
            ->firstOrFail();

        /*
         * The authenticated middleware-provided Platform Admin
         * must always be authoritative.
         */
        $this->assertSame(
            $realAdmin->id,
            $payment->decided_by_platform_admin_id,
        );

        $this->assertNotSame(
            $otherAdmin->id,
            $payment->decided_by_platform_admin_id,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $payment->status,
        );
    }

    public function test_tenant_cannot_list_or_reject_platform_payments(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/payments', [
            'package_months' => 1,
            'trx_id'         => 'DENY000001',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $paymentId = $response->json('data.id');

        /*
         * A tenant Owner is still not a Platform Admin.
         * Platform-wide payment history must not be visible.
         */
        $this->getJson('/api/v1/platform/plan-payments')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        /*
         * The tenant must also be unable to reject its own
         * payment request.
         */
        $this->postJson(
            "/api/v1/platform/plan-payments/{$paymentId}/reject",
            [
                'reason' => 'Attempted tenant rejection.',
            ],
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        /*
         * Failed authorization must not mutate the payment.
         */
        $payment = PlanPaymentRequest::query()
            ->where('uuid', $paymentId)
            ->firstOrFail();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertNull(
            $payment->rejection_reason,
        );

        $this->assertNull(
            $payment->decided_by_platform_admin_id,
        );

        $this->assertNull(
            $payment->decided_at,
        );
    }

    private function makeAuthorizedMember(
        BusinessRole $role,
    ): array {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            $role,
        );

        $this->activateUser($user);

        return [
            $business->refresh(),
            $user->refresh(),
        ];
    }

    private function activateUser($user): void
    {
        $user->forceFill([
            'status'            => 'active',
            'email_verified_at' => now(),
        ])->save();
    }

    private function makePlatformAdmin(
        $user,
    ): PlatformAdmin {
        return PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'status'  => 'active',
        ]);
    }
}
