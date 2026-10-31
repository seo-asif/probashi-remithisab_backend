<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentHistoryApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_payment_history(): void
    {
        $this->getJson('/api/v1/payments')
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );
    }

    public function test_read_only_staff_cannot_view_payment_history(): void
    {
        [, $staff] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/payments')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );
    }

    public function test_owner_can_view_own_pending_payment(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payment = app(
            PaymentRequestService::class,
        )->createUnlimited(
            $business,
            $owner,
            3,
            'HISTORY001',
            '+880 1712-345678',
        );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.items.0.id',
                $payment->uuid,
            )
            ->assertJsonPath(
                'data.items.0.product',
                'unlimited',
            )
            ->assertJsonPath(
                'data.items.0.package_months',
                3,
            )
            ->assertJsonPath(
                'data.items.0.amount_bdt',
                1490,
            )
            ->assertJsonPath(
                'data.items.0.trx_id',
                'HISTORY001',
            )
            ->assertJsonPath(
                'data.items.0.payer_number',
                '01712345678',
            )
            ->assertJsonPath(
                'data.items.0.status',
                'pending',
            )
            ->assertJsonPath(
                'data.items.0.rejection_reason',
                null,
            )
            ->assertJsonPath(
                'data.items.0.decided_at',
                null,
            )
            ->assertJsonPath(
                'data.items.0.applied_at',
                null,
            );

        $this->assertNotNull(
            $response->json(
                'data.items.0.submitted_at',
            ),
        );
    }

    public function test_payment_history_is_isolated_to_current_business(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        [$otherBusiness, $otherOwner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payments = app(
            PaymentRequestService::class,
        );

        $ownPayment = $payments->createUnlimited(
            $business,
            $owner,
            1,
            'OWNHIST001',
            '01712345678',
        );

        $otherPayment = $payments->createUnlimited(
            $otherBusiness,
            $otherOwner,
            1,
            'OTHERH0001',
            '01812345678',
        );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments')
            ->assertOk();

        $ids = collect(
            $response->json('data.items'),
        )->pluck('id');

        $this->assertTrue(
            $ids->contains($ownPayment->uuid),
        );

        $this->assertFalse(
            $ids->contains($otherPayment->uuid),
        );

        $this->assertCount(
            1,
            $response->json('data.items'),
        );
    }

    public function test_business_administrator_can_view_payment_history(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payment = app(
            PaymentRequestService::class,
        )->createUnlimited(
            $business,
            $owner,
            6,
            'ADMINH0001',
            '01912345678',
        );

        $administrator = $this->makeMember(
            $business,
            BusinessRole::Administrator,
        );

        $this->activateUser(
            $administrator,
        );

        Sanctum::actingAs($administrator);

        $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.id',
                $payment->uuid,
            );
    }

    public function test_rejected_payment_history_contains_reason_and_decision_time(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payment = app(
            PaymentRequestService::class,
        )->createUnlimited(
            $business,
            $owner,
            1,
            'REJHIST001',
            '01312345678',
        );

        app(PaymentApprovalService::class)
            ->reject(
                $payment,
                $this->makePlatformAdmin(),
                'TrxID not found in bKash.',
            );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.id',
                $payment->uuid,
            )
            ->assertJsonPath(
                'data.items.0.status',
                'rejected',
            )
            ->assertJsonPath(
                'data.items.0.rejection_reason',
                'TrxID not found in bKash.',
            )
            ->assertJsonPath(
                'data.items.0.applied_at',
                null,
            );

        $this->assertNotNull(
            $response->json(
                'data.items.0.decided_at',
            ),
        );
    }

    public function test_approved_payment_history_contains_applied_time(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payment = app(
            PaymentRequestService::class,
        )->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            12,
            'APPHIST001',
            '01512345678',
        );

        app(PaymentApprovalService::class)
            ->approve(
                $payment,
                $this->makePlatformAdmin(),
            );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.id',
                $payment->uuid,
            )
            ->assertJsonPath(
                'data.items.0.status',
                'approved',
            )
            ->assertJsonPath(
                'data.items.0.rejection_reason',
                null,
            );

        $this->assertNotNull(
            $response->json(
                'data.items.0.decided_at',
            ),
        );

        $this->assertNotNull(
            $response->json(
                'data.items.0.applied_at',
            ),
        );
    }

    public function test_history_is_returned_newest_first(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $payments = app(
            PaymentRequestService::class,
        );

        $first = $payments->createUnlimited(
            $business,
            $owner,
            1,
            'ORDERH0001',
            '01712345678',
        );

        app(PaymentApprovalService::class)
            ->reject(
                $first,
                $this->makePlatformAdmin(),
                'First request rejected.',
            );

        $second = $payments->createUnlimited(
            $business,
            $owner,
            3,
            'ORDERH0002',
            '01812345678',
        );

        app(PaymentApprovalService::class)
            ->reject(
                $second,
                $this->makePlatformAdmin(),
                'Second request rejected.',
            );

        $third = $payments->createUnlimited(
            $business,
            $owner,
            6,
            'ORDERH0003',
            '01912345678',
        );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments')
            ->assertOk();

        $ids = collect(
            $response->json('data.items'),
        )->pluck('id')->values()->all();

        $this->assertSame(
            [
                $third->uuid,
                $second->uuid,
                $first->uuid,
            ],
            $ids,
        );
    }

    public function test_customer_history_does_not_expose_internal_payment_security_fields(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        app(PaymentRequestService::class)
            ->createUnlimited(
                $business,
                $owner,
                1,
                'SAFEHIST01',
                '01712345678',
            );

        Sanctum::actingAs($owner);

        $item = $this
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->json('data.items.0');

        /*
         * TrxID and payer number are intentionally visible to the
         * account Owner/Admin because they are part of the user's
         * own payment history.
         *
         * Internal database/security fields must never be exposed.
         */
        $this->assertArrayHasKey(
            'trx_id',
            $item,
        );

        $this->assertArrayHasKey(
            'payer_number',
            $item,
        );

        foreach ([
            'business_id',
            'submitted_by_user_id',
            'trx_id_hash',
            'decided_by_platform_admin_id',
            'beneficiary_user_id',
            'created_at',
            'updated_at',
        ] as $forbiddenField) {
            $this->assertArrayNotHasKey(
                $forbiddenField,
                $item,
            );
        }
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

    private function activateUser(
        User $user,
    ): void {
        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();
    }

    private function makePlatformAdmin(): PlatformAdmin
    {
        $user = User::factory()->create();

        return PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);
    }
}