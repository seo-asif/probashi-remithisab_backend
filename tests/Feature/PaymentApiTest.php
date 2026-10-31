<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\PlanPaymentStatus;
use App\Models\BusinessSubscription;
use App\Models\PlanPaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_owner_can_submit_unlimited_payment_request(): void
    {
        [$business, $user] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 3,
            'trx_id'         => '9jk4m7xq2p',
            'payer_number'   => '01712345678',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.product', 'sender_plus')
            ->assertJsonPath('data.package_months', 3)
            ->assertJsonPath('data.amount_bdt', 297)
            ->assertJsonPath('data.trx_id', '9JK4M7XQ2P')
            ->assertJsonPath('data.payer_number', '01712345678')
            ->assertJsonPath('data.status', 'pending');

        $payment = PlanPaymentRequest::query()->sole();

        $this->assertSame($business->id, $payment->business_id);
        $this->assertSame($user->id, $payment->submitted_by_user_id);
        $this->assertSame(297, $payment->expected_amount_bdt);
        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );
    }

    public function test_administrator_can_submit_payment_request(): void
    {
        [, $user] = $this->makeAuthorizedMember(
            BusinessRole::Administrator,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 1,
            'trx_id'         => 'AB12CD34EF',
            'payer_number'   => '01812345678',
        ])->assertCreated();

        $this->assertDatabaseCount(
            'plan_payment_requests',
            1,
        );
    }

    public function test_operator_cannot_submit_or_list_plan_payments(): void
    {
        [, $user] = $this->makeAuthorizedMember(
            BusinessRole::Operator,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 1,
            'trx_id'         => 'AB12CD34EF',
            'payer_number'   => '01712345678',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );

        $this->getJson('/api/v1/payments')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_read_only_staff_cannot_submit_or_list_plan_payments(): void
    {
        [, $user] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 1,
            'trx_id'         => 'AB12CD34EF',
            'payer_number'   => '01712345678',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );

        $this->getJson('/api/v1/payments')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_client_cannot_tamper_with_price_status_or_entitlement(): void
    {
        [$business, $user] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months'      => 12,
            'trx_id'              => 'ZX98YU76TR',
            'payer_number'        => '01912345678',

            // Malicious/untrusted fields.
            'expected_amount_bdt' => 1,
            'amount_bdt'          => 1,
            'status'              => 'approved',
            'product_type'        => 'sender_topup',
            'beneficiary_user_id' => $user->id,
            'applied_at'          => now()->toISOString(),
            'access_source'       => 'paid',
            'access_starts_at'    => now()->toISOString(),
            'access_ends_at'      => now()->addYears(100)->toISOString(),
        ])->assertCreated();

        $payment = PlanPaymentRequest::query()->sole();

        $this->assertSame(1188, $payment->expected_amount_bdt);
        $this->assertSame('sender_plus', $payment->product_type->value);
        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );
        $this->assertSame($user->id, $payment->beneficiary_user_id);
        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
                'tier'        => 'unlimited',
            ],
        );
    }

    public function test_submitting_payment_does_not_activate_unlimited(): void
    {
        [$business, $user] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 6,
            'trx_id'         => 'MN45PQ67RS',
            'payer_number'   => '01612345678',
        ])->assertCreated();

        $payment = PlanPaymentRequest::query()->sole();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertFalse(
            BusinessSubscription::query()
                ->where('business_id', $business->id)
                ->where('tier', 'unlimited')
                ->exists(),
        );
    }

    public function test_payment_list_is_isolated_to_current_business(): void
    {
        [$businessA, $userA] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($userA);

        $responseA = $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 1,
            'trx_id'         => 'AA11BB22CC',
            'payer_number'   => '01712345678',
        ])->assertCreated();

        $paymentAId = $responseA->json('data.id');

        [$businessB, $userB] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($userB);

        $responseB = $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 3,
            'trx_id'         => 'DD33EE44FF',
            'payer_number'   => '01812345678',
        ])->assertCreated();

        $paymentBId = $responseB->json('data.id');

        $this->assertNotSame(
            $businessA->id,
            $businessB->id,
        );

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data.items'))
            ->pluck('id')
            ->all();

        $this->assertContains($paymentAId, $ids);
        $this->assertNotContains($paymentBId, $ids);
    }

    public function test_client_cannot_choose_payment_business_or_submitter(): void
    {
        [$businessA, $ownerA] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        [$businessB, $ownerB] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($ownerA);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months'       => 1,
            'trx_id'               => 'TENANT0001',
            'payer_number'         => '01712345678',

            // Malicious cross-tenant identity fields.
            'business_id'          => $businessB->id,
            'submitted_by_user_id' => $ownerB->id,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $payment = PlanPaymentRequest::query()->sole();

        /*
         * The authenticated tenant context and authenticated user
         * must be authoritative. Client-supplied IDs are ignored.
         */
        $this->assertSame(
            $businessA->id,
            $payment->business_id,
        );

        $this->assertSame(
            $ownerA->id,
            $payment->submitted_by_user_id,
        );

        $this->assertNotSame(
            $businessB->id,
            $payment->business_id,
        );

        $this->assertNotSame(
            $ownerB->id,
            $payment->submitted_by_user_id,
        );
    }
    public function test_client_cannot_force_referral_discount_or_reward(): void
{
    [, $owner] = $this->makeAuthorizedMember(
        BusinessRole::Owner,
    );

    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/payments', [
        'product'        => 'sender_plus',
        'package_months' => 1,
        'trx_id' => 'REWARD0001',
        'payer_number' => '01712345678',

        // Malicious/untrusted referral and pricing fields.
        'referral_reward_id' => 999999,
        'referral_discount_bdt' => 150,
        'discount_bdt' => 150,
        'payable_amount_bdt' => 370,
        'expected_amount_bdt' => 370,
        'reward_status' => 'available',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount_bdt', 99);

    $payment = PlanPaymentRequest::query()->sole();

    /*
     * With no legitimate server-side referral reward,
     * the authoritative package price must remain unchanged.
     */
    $this->assertSame(
        99,
        $payment->expected_amount_bdt,
    );

    $this->assertDatabaseCount(
        'referral_rewards',
        0,
    );
}

    public function test_invalid_package_is_rejected(): void
    {
        [, $user] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 24,
            'trx_id'         => 'AB12CD34EF',
            'payer_number'   => '01712345678',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_unauthenticated_user_cannot_access_payment_endpoints(): void
    {
        $this->getJson('/api/v1/payments')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/payments', [
            'product'        => 'sender_plus',
            'package_months' => 1,
            'trx_id'         => 'AB12CD34EF',
            'payer_number'   => '01712345678',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    private function makeAuthorizedMember(
        BusinessRole $role,
    ): array {
        $business = $this->makeBusiness();

        $user = $this->makeMember(
            $business,
            $role,
        );

        $user->forceFill([
            'status'            => 'active',
            'email_verified_at' => now(),
        ])->save();

        return [
            $business->refresh(),
            $user->refresh(),
        ];
    }
}
