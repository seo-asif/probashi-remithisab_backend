<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\PlanPaymentRequest;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class IndependentPaymentSubmissionTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_sender_plus_uses_authenticated_beneficiary_and_server_price(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        $payment = app(PaymentRequestService::class)->createIndependent(
            $business, $owner, PlanPaymentProduct::SenderPlus, 3, 'SEND000001', '01712345678'
        );

        $this->assertSame(PlanPaymentProduct::SenderPlus, $payment->product_type);
        $this->assertSame($owner->id, $payment->beneficiary_user_id);
        $this->assertSame($owner->id, $payment->submitted_by_user_id);
        $this->assertSame(297, $payment->expected_amount_bdt);
        $this->assertSame(PlanPaymentStatus::Pending, $payment->status);
    }

    public function test_manager_pro_is_business_owned_and_server_priced(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        $payment = app(PaymentRequestService::class)->createIndependent(
            $business, $owner, PlanPaymentProduct::ManagerPro, 12, 'MANA000001', '01712345678'
        );

        $this->assertSame(PlanPaymentProduct::ManagerPro, $payment->product_type);
        $this->assertSame($business->id, $payment->business_id);
        $this->assertNull($payment->beneficiary_user_id);
        $this->assertSame(3588, $payment->expected_amount_bdt);
    }

    public function test_legacy_and_topup_products_are_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        foreach ([PlanPaymentProduct::Unlimited, PlanPaymentProduct::SenderTopUp, PlanPaymentProduct::ManagerTopUp] as $product) {
            $this->expectApiError(fn () => app(PaymentRequestService::class)->createIndependent(
                $business, $owner, $product, 1, 'PROD000001', '01712345678'
            ), 'PLAN_PRODUCT_INVALID', 422);
        }
        $this->assertDatabaseCount('plan_payment_requests', 0);
    }

    public function test_invalid_duration_and_payment_identifiers_are_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        $service = app(PaymentRequestService::class);
        $this->expectApiError(fn () => $service->createIndependent($business, $owner, PlanPaymentProduct::SenderPlus, 2, 'DURA000001', '01712345678'), 'PLAN_PACKAGE_INVALID', 422);
        $this->expectApiError(fn () => $service->createIndependent($business, $owner, PlanPaymentProduct::SenderPlus, 1, 'SHORT', '01712345678'), 'PAYMENT_TRX_INVALID', 422);
        $this->expectApiError(fn () => $service->createIndependent($business, $owner, PlanPaymentProduct::SenderPlus, 1, 'NUMB000001', '123'), 'PAYMENT_PAYER_NUMBER_INVALID', 422);
        $this->assertDatabaseCount('plan_payment_requests', 0);
    }

    public function test_duplicate_transaction_id_is_rejected_across_products_and_businesses(): void
    {
        $a = $this->makeBusiness();
        $ownerA = $this->makeMember($a);
        $b = $this->makeBusiness();
        $ownerB = $this->makeMember($b);
        $service = app(PaymentRequestService::class);
        $service->createIndependent($a, $ownerA, PlanPaymentProduct::SenderPlus, 1, 'DUPL000011', '01712345678');
        $this->expectApiError(fn () => $service->createIndependent($b, $ownerB, PlanPaymentProduct::ManagerPro, 1, 'dupl-000011', '01712345678'), 'PAYMENT_TRX_ALREADY_USED', 409);
        $this->assertDatabaseCount('plan_payment_requests', 1);
    }

    public function test_two_pending_requests_block_a_third_across_products(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        $service = app(PaymentRequestService::class);
        $service->createIndependent($business, $owner, PlanPaymentProduct::SenderPlus, 1, 'PEND000011', '01712345678');
        $service->createIndependent($business, $owner, PlanPaymentProduct::ManagerPro, 1, 'PEND000012', '01712345678');
        $this->expectApiError(fn () => $service->createIndependent($business, $owner, PlanPaymentProduct::SenderPlus, 1, 'PEND000013', '01712345678'), 'PLAN_PAYMENT_PENDING_LIMIT', 409);
        $this->assertDatabaseCount('plan_payment_requests', 2);
    }

    public function test_non_owner_or_administrator_cannot_submit(): void
    {
        $business = $this->makeBusiness();
        $member = $this->makeMember($business, BusinessRole::Operator);
        $this->expectApiError(fn () => app(PaymentRequestService::class)->createIndependent(
            $business, $member, PlanPaymentProduct::SenderPlus, 1, 'ROLE000011', '01712345678'
        ), 'PLAN_PAYMENT_FORBIDDEN', 403);
        $this->assertDatabaseCount('plan_payment_requests', 0);
    }

    private function expectApiError(callable $callback, string $code, int $status): void
    {
        try {
            $callback();
            $this->fail("Expected {$code} exception.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);
        }
    }
}
