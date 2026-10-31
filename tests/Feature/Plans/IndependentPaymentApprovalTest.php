<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Models\ManagerSubscription;
use App\Models\PlatformAdmin;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class IndependentPaymentApprovalTest extends TestCase
{
    use RefreshDatabase, MakesTenants;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create(['user_id' => User::factory()->create()->id]);
    }

    private function payment(PlanPaymentProduct $product, int $months = 1)
    {
        $business = $this->makeBusiness();
        $user = $this->makeMember($business);
        $payment = app(PaymentRequestService::class)->createIndependent(
            $business, $user, $product, $months,
            'APPROVE123', '01712345678',
        );
        return [$business, $user, $payment];
    }

    public function test_sender_plus_approval_creates_user_subscription_and_retry_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-10-31 12:00:00 UTC');
        [$business, $user, $payment] = $this->payment(PlanPaymentProduct::SenderPlus, 3);
        $approvals = app(PaymentApprovalService::class);
        $admin = $this->admin();
        $this->assertSame(PlanPaymentStatus::Approved, $approvals->approve($payment, $admin)->status);
        $subscription = SenderSubscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('plus', $subscription->tier);
        $this->assertSame('paid', $subscription->access_source);
        $this->assertSame('2027-01-31', $subscription->access_ends_at->format('Y-m-d'));
        $this->assertSame(0, ManagerSubscription::count());
        $approvals->approve($payment, $admin);
        $this->assertSame('2027-01-31', $subscription->fresh()->access_ends_at->format('Y-m-d'));
    }

    public function test_manager_pro_approval_creates_business_subscription_only(): void
    {
        [$business, $user, $payment] = $this->payment(PlanPaymentProduct::ManagerPro, 1);
        app(PaymentApprovalService::class)->approve($payment, $this->admin());
        $this->assertSame('pro', ManagerSubscription::where('business_id', $business->id)->firstOrFail()->tier);
        $this->assertSame(0, SenderSubscription::count());
    }

    public function test_active_sender_renewal_extends_existing_expiry(): void
    {
        CarbonImmutable::setTestNow('2026-10-31 12:00:00 UTC');
        [$business, $user, $payment] = $this->payment(PlanPaymentProduct::SenderPlus, 1);
        SenderSubscription::create([
            'user_id' => $user->id,
            'tier' => 'plus',
            'access_source' => 'paid',
            'access_starts_at' => CarbonImmutable::parse('2026-10-01', 'UTC'),
            'access_ends_at' => CarbonImmutable::parse('2026-11-15', 'UTC'),
        ]);
        app(PaymentApprovalService::class)->approve($payment, $this->admin());
        $this->assertSame('2026-12-15', SenderSubscription::firstOrFail()->access_ends_at->format('Y-m-d'));
    }

    public function test_tampered_price_rolls_back_without_approval(): void
    {
        [$business, $user, $payment] = $this->payment(PlanPaymentProduct::SenderPlus);
        $payment->forceFill(['expected_amount_bdt' => 1])->save();
        try {
            app(PaymentApprovalService::class)->approve($payment, $this->admin());
            $this->fail('Tampered price must be rejected.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('PLAN_PAYMENT_AMOUNT_INVALID', $e->errorCode);
        }
        $this->assertSame(PlanPaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, SenderSubscription::count());
    }

    public function test_tampered_sender_beneficiary_is_rejected(): void
    {
        [$business, $user, $payment] = $this->payment(PlanPaymentProduct::SenderPlus);
        $payment->forceFill(['beneficiary_user_id' => null])->save();
        try {
            app(PaymentApprovalService::class)->approve($payment, $this->admin());
            $this->fail('Invalid beneficiary must be rejected.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('PLAN_PAYMENT_BENEFICIARY_INVALID', $e->errorCode);
        }
        $this->assertSame(0, SenderSubscription::count());
    }
}
