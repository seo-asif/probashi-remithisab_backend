<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\PlanPaymentProduct;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Plans\IndependentPlanPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentDecisionAccessTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): PlanPaymentRequest
    {
        $business = Business::factory()->create();

        /** @var User $user */
        $user = User::factory()->create();

        return PlanPaymentRequest::create([
            'business_id' => $business->id,
            'submitted_by_user_id' => $user->id,
            'beneficiary_user_id' => $user->id,
            'product_type' => PlanPaymentProduct::SenderPlus,
            'package_months' => 1,
            'expected_amount_bdt' => IndependentPlanPackages::priceBdt(
                PlanPaymentProduct::SenderPlus,
                1,
            ),
            'trx_id' => 'TEST123456',
            'trx_id_hash' => hash('sha256', 'test-payment-decision'),
            'payer_number' => '01712345678',
        ]);
    }

    public function test_guest_cannot_open_payment_decision(): void
    {
        $payment = $this->payment();

        $this->getJson("/admin/payments/{$payment->id}/decision")
            ->assertUnauthorized();
    }

    public function test_guest_cannot_submit_payment_decision(): void
    {
        $payment = $this->payment();

        $this->postJson("/admin/payments/{$payment->id}/decision", [
            'decision' => 'approve',
            'confirm_payment_id' => $payment->id,
        ])->assertUnauthorized();
    }

    public function test_regular_user_cannot_submit_payment_decision(): void
    {
        $payment = $this->payment();

        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post("/admin/payments/{$payment->id}/decision", [
                'decision' => 'approve',
                'confirm_payment_id' => $payment->id,
            ])
            ->assertForbidden();
    }

    public function test_admin_without_mfa_cannot_submit_payment_decision(): void
    {
        $payment = $this->payment();

        /** @var User $user */
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        $this->actingAs($user)
            ->post("/admin/payments/{$payment->id}/decision", [
                'decision' => 'approve',
                'confirm_payment_id' => $payment->id,
            ])
            ->assertForbidden();
    }
}
