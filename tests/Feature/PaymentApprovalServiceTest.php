<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\BusinessSubscription;
use App\Models\ManagerSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Support\Plans\IndependentPlanPackages;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanLimits;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentApprovalServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private PaymentRequestService $requests;
    private PaymentApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = app(PaymentRequestService::class);
        $this->approvals = app(PaymentApprovalService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_corrupt_pending_payment_cannot_be_approved(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'STATE00001',
            '01712345678',
        );

        $payment->forceFill([
            'applied_at' => now('UTC'),
        ])->save();

        $admin = $this->makePlatformAdmin();

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $payment,
                $admin,
            ),
            'PLAN_PAYMENT_STATE_INVALID',
            409,
        );

        $this->assertDatabaseCount(
            'business_subscriptions',
            0,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );
    }

    public function test_corrupt_pending_payment_cannot_be_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'STATE00002',
            '01812345678',
        );

        $payment->forceFill([
            'decided_at' => now('UTC'),
        ])->save();

        $admin = $this->makePlatformAdmin();

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $payment,
                $admin,
                'Payment could not be verified.',
            ),
            'PLAN_PAYMENT_STATE_INVALID',
            409,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );
    }

    public function test_corrupt_rejected_payment_is_not_silently_accepted(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'STATE00003',
            '01912345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->reject(
            $payment,
            $admin,
            'TrxID not found in bKash.',
        );

        $payment->refresh()->forceFill([
            'applied_at' => now('UTC'),
        ])->save();

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $payment,
                $admin,
                'TrxID not found in bKash.',
            ),
            'PLAN_PAYMENT_STATE_INVALID',
            409,
        );
    }

  public function test_pending_legacy_unlimited_payment_cannot_be_approved(): void
{
    $business = $this->makeBusiness();

    $owner = $this->makeMember(
        $business,
        BusinessRole::Owner,
    );

    $payment = $this->requests->createUnlimited(
        $business,
        $owner,
        3,
        'PKGAPP0001',
        '01712345678',
    );

    $admin = $this->makePlatformAdmin();

    $this->assertApiException(
        fn() => $this->approvals->approve(
            $payment,
            $admin,
        ),
        'PLAN_PAYMENT_PRODUCT_RETIRED',
        409,
    );

    $payment->refresh();

    $this->assertSame(
        PlanPaymentStatus::Pending,
        $payment->status,
    );

    $this->assertNull($payment->applied_at);
    $this->assertNull($payment->decided_at);

    $this->assertDatabaseMissing(
        'business_subscriptions',
        [
            'business_id' => $business->id,
            'access_source' => 'paid',
        ],
    );

    $this->assertDatabaseMissing(
        'audit_logs',
        [
            'action' => 'plan_payment.approved',
            'target_type' => 'PlanPaymentRequest',
            'target_id' => $payment->id,
        ],
    );
}

    public function test_approving_same_payment_twice_does_not_extend_twice(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-21 00:00:00', 'UTC')
        );

        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'IDEM000001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $first = $this->approvals->approve($payment, $admin);

        $subscription = SenderSubscription::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $firstEndsAt = $subscription->access_ends_at;
        $firstAppliedAt = $first->applied_at;

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-25 00:00:00', 'UTC')
        );

        $second = $this->approvals->approve(
            $payment->refresh(),
            $admin,
        );

        $subscription->refresh();

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $second->status,
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo($firstEndsAt),
        );

        $this->assertTrue(
            $second->applied_at->equalTo($firstAppliedAt),
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                CarbonImmutable::parse(
                    '2026-11-21 00:00:00',
                    'UTC',
                ),
            ),
        );
    }

    public function test_rejected_payment_cannot_be_approved(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'REJAPP0001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->reject(
            $payment,
            $admin,
            'TrxID not found in bKash.',
        );

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $payment,
                $admin,
            ),
            'PLAN_PAYMENT_ALREADY_DECIDED',
            409,
        );

        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Rejected,
            $payment->status,
        );

        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_approved_payment_cannot_be_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::ManagerPro,
            1,
            'APPREJ0001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $payment,
                $admin,
                'Reject after approval.',
            ),
            'PLAN_PAYMENT_ALREADY_DECIDED',
            409,
        );

        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $payment->status,
        );

        $this->assertNotNull($payment->applied_at);
    }

    public function test_repeated_rejection_is_idempotent(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'REJIDEM001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $first = $this->approvals->reject(
            $payment,
            $admin,
            'TrxID not found in bKash.',
        );

        $firstDecidedAt = $first->decided_at;

        $second = $this->approvals->reject(
            $payment,
            $admin,
            'A different reason must not overwrite it.',
        );

        $this->assertSame(
            PlanPaymentStatus::Rejected,
            $second->status,
        );

        $this->assertSame(
            'TrxID not found in bKash.',
            $second->decision_reason,
        );

        $this->assertTrue(
            $second->decided_at->equalTo(
                $firstDecidedAt,
            ),
        );

        $this->assertNull($second->applied_at);
    }

    public function test_rejection_reason_is_required_and_limited_to_255_characters(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);
        $admin = $this->makePlatformAdmin();

        $first = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'REASON0001',
            '01712345678',
        );

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $first,
                $admin,
                '   ',
            ),
            'PLAN_PAYMENT_REJECTION_REASON_INVALID',
            422,
        );

        $second = $this->requests->createUnlimited(
            $business,
            $owner,
            3,
            'REASON0002',
            '01712345678',
        );

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $second,
                $admin,
                str_repeat('x', 256),
            ),
            'PLAN_PAYMENT_REJECTION_REASON_INVALID',
            422,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $first->refresh()->status,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $second->refresh()->status,
        );
    }

    public function test_suspended_platform_admin_cannot_approve_or_reject(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $approvePayment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'SUSPAPP001',
            '01712345678',
        );

        $rejectPayment = $this->requests->createUnlimited(
            $business,
            $owner,
            3,
            'SUSPREJ001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin(
            AccountStatus::Suspended,
        );

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $approvePayment,
                $admin,
            ),
            'PLATFORM_ADMIN_FORBIDDEN',
            403,
        );

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $rejectPayment,
                $admin,
                'Should not be allowed.',
            ),
            'PLATFORM_ADMIN_FORBIDDEN',
            403,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $approvePayment->refresh()->status,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $rejectPayment->refresh()->status,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_platform_admin_underlying_user_must_remain_active_and_verified(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $approvePayment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'USRSTAT001',
            '01712345678',
        );

        $rejectPayment = $this->requests->createUnlimited(
            $business,
            $owner,
            3,
            'USRVER0001',
            '01812345678',
        );

        /*
         * PlatformAdmin row is Active, but its underlying
         * user account has been suspended.
         */
        $suspendedUserAdmin = $this->makePlatformAdmin();

        $suspendedUserAdmin->user->forceFill([
            'status' => AccountStatus::Suspended,
        ])->save();

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $approvePayment,
                $suspendedUserAdmin,
            ),
            'PLATFORM_ADMIN_FORBIDDEN',
            403,
        );

        /*
         * PlatformAdmin row is Active, but its underlying
         * user has lost email verification.
         */
        $unverifiedUserAdmin = $this->makePlatformAdmin();

        $unverifiedUserAdmin->user->forceFill([
            'email_verified_at' => null,
        ])->save();

        $this->assertApiException(
            fn() => $this->approvals->reject(
                $rejectPayment,
                $unverifiedUserAdmin,
                'Should not be allowed.',
            ),
            'PLATFORM_ADMIN_FORBIDDEN',
            403,
        );

        /*
         * Neither unauthorized operation may mutate payment state.
         */
        $this->assertSame(
            PlanPaymentStatus::Pending,
            $approvePayment->refresh()->status,
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $rejectPayment->refresh()->status,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_successful_payment_approval_is_audited(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus, 1,
            'AUDITAPP01',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'business_id'   => $business->id,
                'actor_user_id' => $admin->user_id,
                'action'        => 'plan_payment.approved',
                'target_type'   => 'PlanPaymentRequest',
                'target_id'     => $approved->id,
            ],
        );
    }

    public function test_successful_payment_rejection_is_audited(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );
        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'AUDITAPP01',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $rejected = $this->approvals->reject(
            $payment,
            $admin,
            'Payment could not be verified.',
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'business_id'   => $business->id,
                'actor_user_id' => $admin->user_id,
                'action'        => 'plan_payment.rejected',
                'target_type'   => 'PlanPaymentRequest',
                'target_id'     => $rejected->id,
            ],
        );
    }

    public function test_approval_retry_does_not_duplicate_audit_event(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'AUDITRET01',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $payment,
            $admin,
        );

        /*
         * Idempotent retry.
         */
        $this->approvals->approve(
            $payment->refresh(),
            $admin,
        );

        $this->assertSame(
            1,
            \App\Models\AuditLog::query()
                ->where(
                    'business_id',
                    $business->id,
                )
                ->where(
                    'action',
                    'plan_payment.approved',
                )
                ->where(
                    'target_type',
                    'PlanPaymentRequest',
                )
                ->where(
                    'target_id',
                    $payment->id,
                )
                ->count(),
        );
    }

    public function test_rejection_retry_does_not_duplicate_audit_event(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'AUDITRET02',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->reject(
            $payment,
            $admin,
            'Payment could not be verified.',
        );

        /*
         * Idempotent retry.
         */
        $this->approvals->reject(
            $payment->refresh(),
            $admin,
            'Payment could not be verified.',
        );

        $this->assertSame(
            1,
            \App\Models\AuditLog::query()
                ->where(
                    'business_id',
                    $business->id,
                )
                ->where(
                    'action',
                    'plan_payment.rejected',
                )
                ->where(
                    'target_type',
                    'PlanPaymentRequest',
                )
                ->where(
                    'target_id',
                    $payment->id,
                )
                ->count(),
        );
    }

    public function test_approval_revalidates_stored_package(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'BADPKG0001',
            '01712345678',
        );

        /*
         * Simulate a corrupted/tampered stored payment.
         */
        $payment->forceFill([
            'package_months' => 2,
        ])->save();

        $admin = $this->makePlatformAdmin();

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $payment,
                $admin,
            ),
            'PLAN_PAYMENT_PACKAGE_INVALID',
            409,
        );

        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_approval_revalidates_server_price(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'BADAMT0001',
            '01712345678',
        );
        /*
         * Correct server price is 520 BDT.
         */
        $payment->forceFill([
            'expected_amount_bdt' => 521,
        ])->save();

        $admin = $this->makePlatformAdmin();

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $payment,
                $admin,
            ),
            'PLAN_PAYMENT_AMOUNT_INVALID',
            409,
        );

        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_paid_top_up_approval_fails_closed_until_price_is_configured(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        /*
         * PaymentRequestService intentionally cannot create paid
         * top-up requests yet because no BDT top-up price exists.
         *
         * Insert one directly to prove the approval layer still
         * refuses to grant it.
         */
        $payment = PlanPaymentRequest::create([
            'business_id'          => $business->id,
            'submitted_by_user_id' => $owner->id,
            'beneficiary_user_id'  => $owner->id,
            'product_type'         => PlanPaymentProduct::SenderTopUp,
            'package_months'       => null,
            'expected_amount_bdt'  => 1,
            'trx_id'               => 'TOPUP00001',
            'trx_id_hash'          => hash(
                'sha256',
                'topup-payment-test',
            ),
            'payer_number'         => '01712345678',
        ]);

        $admin = $this->makePlatformAdmin();

        $this->assertApiException(
            fn() => $this->approvals->approve(
                $payment,
                $admin,
            ),
            'PLAN_TOPUP_PRICE_NOT_CONFIGURED',
            409,
        );

        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id'   => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_manager_pro_purchase_preserves_legacy_trial_history(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 00:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $trialUsedAt = $now->subDay();

        $legacySubscription = BusinessSubscription::create([
            'business_id'      => $business->id,
            'tier'             => PlanLimits::UNLIMITED,
            'access_source'    => 'trial',
            'access_starts_at' => $trialUsedAt,
            'access_ends_at'   => $now->addDays(6),
            'trial_used_at'    => $trialUsedAt,
        ]);

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::ManagerPro,
            1,
            'TRIALPAY01',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $approved->status,
        );

        $subscription = ManagerSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::MANAGER_PRO,
            $subscription->tier,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->access_starts_at->equalTo($now),
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                $now->addMonthNoOverflow(),
            ),
        );

        $legacySubscription->refresh();

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $legacySubscription->tier,
        );

        $this->assertSame(
            'trial',
            $legacySubscription->access_source,
        );

        $this->assertTrue(
            $legacySubscription->trial_used_at->equalTo(
                $trialUsedAt,
            ),
        );

        $this->assertTrue(
            $legacySubscription->access_ends_at->equalTo(
                $now->addDays(6),
            ),
        );
    }

    private function makePlatformAdmin(
        AccountStatus $status = AccountStatus::Active,
    ): PlatformAdmin {
        $user = User::factory()->create();

        return PlatformAdmin::create([
            'user_id' => $user->id,
            'status'  => $status,
        ]);
    }

    private function assertApiException(
        callable $callback,
        string $code,
        int $status,
    ): ApiException {
        try {
            $callback();
        } catch (ApiException $e) {
            $this->assertSame(
                $code,
                $e->errorCode,
            );

            $this->assertSame(
                $status,
                $e->status,
            );

            return $e;
        }

        $this->fail(
            "Expected ApiException {$code} was not thrown.",
        );
    }

    public function test_sender_plus_approval_activates_paid_access(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-21 00:00:00', 'UTC')
        );

        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            3,
            'SNDAPP0001',
            '01712345678',
        );

        $this->assertSame(
            IndependentPlanPackages::priceBdt(
                PlanPaymentProduct::SenderPlus,
                3,
            ),
            $payment->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $approved->status,
        );

        $this->assertSame(
            $admin->id,
            $approved->decided_by_platform_admin_id,
        );

        $this->assertNotNull($approved->applied_at);

        $subscription = SenderSubscription::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::SENDER_PLUS,
            $subscription->tier,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->access_starts_at->equalTo(
                CarbonImmutable::parse(
                    '2026-10-21 00:00:00',
                    'UTC',
                ),
            ),
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                CarbonImmutable::parse(
                    '2027-01-21 00:00:00',
                    'UTC',
                ),
            ),
        );
    }

    public function test_manager_pro_approval_activates_paid_access(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-21 00:00:00', 'UTC')
        );

        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::ManagerPro,
            3,
            'MGRAPP0001',
            '01712345678',
        );

        $this->assertNull($payment->beneficiary_user_id);

        $this->assertSame(
            IndependentPlanPackages::priceBdt(
                PlanPaymentProduct::ManagerPro,
                3,
            ),
            $payment->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $approved->status,
        );

        $this->assertSame(
            $admin->id,
            $approved->decided_by_platform_admin_id,
        );

        $this->assertNotNull($approved->applied_at);

        $subscription = ManagerSubscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::MANAGER_PRO,
            $subscription->tier,
        );

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->access_starts_at->equalTo(
                CarbonImmutable::parse(
                    '2026-10-21 00:00:00',
                    'UTC',
                ),
            ),
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                CarbonImmutable::parse(
                    '2027-01-21 00:00:00',
                    'UTC',
                ),
            ),
        );
    }
}
