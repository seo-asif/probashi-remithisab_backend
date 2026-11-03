<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ManagerSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\AuditLog;

final class PaymentDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function authorizeAdmin(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        $this->actingAs($user)->withSession([
            'admin_mfa.user_id' => $user->id,
            'admin_mfa.last_activity' => time(),
        ]);
    }

    private function createPayment(PlanPaymentProduct $product, string $reference): PlanPaymentRequest
    {
        $business = Business::factory()->create();
        /** @var User $owner */
        $owner = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        BusinessMembership::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => BusinessRole::Owner,
            'status' => AccountStatus::Active,
        ]);

        return app(PaymentRequestService::class)->createIndependent(
            $business,
            $owner,
            $product,
            3,
            $reference,
            '01712345678',
        );
    }

    private function decide(PlanPaymentRequest $payment, string $decision, ?string $reason = null)
    {
        $data = [
            'decision' => $decision,
            'confirm_payment_id' => $payment->id,
        ];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        return $this->post(route('admin.payments.decision.submit', ['payment' => $payment->id]), $data);
    }


    private function assertDecisionAudit(
        PlanPaymentRequest $payment,
        string $action,
    ): void {
        $payment->refresh();

        $admin = $payment->decidedByPlatformAdmin()
            ->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => $action,
            'actor_user_id' => $admin->user_id,
            'business_id' => $payment->business_id,
            'target_type' => 'PlanPaymentRequest',
            'target_id' => $payment->id,
        ]);

        $this->assertAuditCount($payment, $action, 1);
    }

    private function assertAuditCount(
        PlanPaymentRequest $payment,
        string $action,
        int $expected,
    ): void {
        $actual = AuditLog::query()
            ->where('action', $action)
            ->where('target_type', 'PlanPaymentRequest')
            ->where('target_id', $payment->id)
            ->count();

        $this->assertSame($expected, $actual);
    }


    public function test_authorized_admin_approves_sender_plus(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::SenderPlus, 'SENDER0001');
        $this->authorizeAdmin();
        $this->decide($payment, 'approve')
            ->assertRedirect(route('admin.payments.decision', ['payment' => $payment->id]));

        $payment->refresh();
        $this->assertSame(PlanPaymentStatus::Approved, $payment->status);
        $this->assertNotNull($payment->applied_at);
        $subscription = SenderSubscription::query()->where('user_id', $payment->beneficiary_user_id)->firstOrFail();
        $this->assertSame('paid', $subscription->access_source);
        $this->assertNotNull($subscription->access_ends_at);
        $this->assertDatabaseCount('sender_subscriptions', 1);
        $this->assertDecisionAudit($payment, 'plan_payment.approved');
$this->assertAuditCount($payment, 'plan_payment.rejected', 0);
    }

    public function test_authorized_admin_approves_manager_pro(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::ManagerPro, 'MANAGER001');
        $this->authorizeAdmin();
        $this->decide($payment, 'approve')->assertRedirect();

        $this->assertSame(PlanPaymentStatus::Approved, $payment->refresh()->status);
        $subscription = ManagerSubscription::query()->where('business_id', $payment->business_id)->firstOrFail();
        $this->assertSame('paid', $subscription->access_source);
        $this->assertNotNull($subscription->access_ends_at);
        $this->assertDatabaseCount('manager_subscriptions', 1);
        $this->assertDecisionAudit($payment, 'plan_payment.approved');
$this->assertAuditCount($payment, 'plan_payment.rejected', 0);
    }

    public function test_rejection_records_reason_without_entitlement(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::ManagerPro, 'REJECT0001');
        $this->authorizeAdmin();
        $this->decide($payment, 'reject', 'Demo payment could not be verified')->assertRedirect();

        $payment->refresh();
        $this->assertSame(PlanPaymentStatus::Rejected, $payment->status);
        $this->assertSame('Demo payment could not be verified', $payment->decision_reason);
        $this->assertNull($payment->applied_at);
        $this->assertDatabaseCount('manager_subscriptions', 0);
        $this->assertDecisionAudit($payment, 'plan_payment.rejected');
$this->assertAuditCount($payment, 'plan_payment.approved', 0);
    }

    public function test_repeated_approval_does_not_extend_subscription_twice(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::SenderPlus, 'REPEAT0001');
        $this->authorizeAdmin();
        $this->decide($payment, 'approve')->assertRedirect();
        $originalExpiry = SenderSubscription::query()->where('user_id', $payment->beneficiary_user_id)->firstOrFail()->access_ends_at->toISOString();
        $this->decide($payment, 'approve')->assertSessionHas('payment_notice');

        $this->assertSame($originalExpiry, SenderSubscription::query()->where('user_id', $payment->beneficiary_user_id)->firstOrFail()->access_ends_at->toISOString());
        $this->assertDatabaseCount('sender_subscriptions', 1);
        $this->assertDecisionAudit($payment, 'plan_payment.approved');
    }

    public function test_decided_payments_cannot_change_decision(): void
    {
        $approved = $this->createPayment(PlanPaymentProduct::SenderPlus, 'APPROVE001');
        $rejected = $this->createPayment(PlanPaymentProduct::ManagerPro, 'REJECT0002');
        $this->authorizeAdmin();

        $this->decide($approved, 'approve')->assertRedirect();
        $this->decide($approved, 'reject', 'Attempted reversal')->assertSessionHas('payment_notice');
        $this->assertSame(PlanPaymentStatus::Approved, $approved->refresh()->status);

        $this->decide($rejected, 'reject', 'Original reason')->assertRedirect();
        $this->decide($rejected, 'approve')->assertSessionHas('payment_notice');
        $this->assertSame(PlanPaymentStatus::Rejected, $rejected->refresh()->status);
        $this->assertSame('Original reason', $rejected->decision_reason);
        $this->assertDatabaseCount('manager_subscriptions', 0);
        $this->assertDecisionAudit($approved, 'plan_payment.approved');
$this->assertAuditCount($approved, 'plan_payment.rejected', 0);

$this->assertDecisionAudit($rejected, 'plan_payment.rejected');
$this->assertAuditCount($rejected, 'plan_payment.approved', 0);
    }

    public function test_rejection_reason_is_required(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::ManagerPro, 'INVALID001');
        $this->authorizeAdmin();
        $this->decide($payment, 'reject')->assertSessionHasErrors('reason');
        $this->assertSame(PlanPaymentStatus::Pending, $payment->refresh()->status);
        $this->assertAuditCount($payment, 'plan_payment.rejected', 0);
$this->assertAuditCount($payment, 'plan_payment.approved', 0);
    }

    public function test_mismatched_confirmation_id_is_rejected(): void
    {
        $payment = $this->createPayment(PlanPaymentProduct::SenderPlus, 'INVALID002');
        $this->authorizeAdmin();
        $this->post(route('admin.payments.decision.submit', ['payment' => $payment->id]), [
            'decision' => 'approve',
            'confirm_payment_id' => $payment->id + 999,
        ])->assertSessionHasErrors('confirm_payment_id');
        $this->assertSame(PlanPaymentStatus::Pending, $payment->refresh()->status);
        $this->assertAuditCount($payment, 'plan_payment.approved', 0);
$this->assertAuditCount($payment, 'plan_payment.rejected', 0);
    }
}
