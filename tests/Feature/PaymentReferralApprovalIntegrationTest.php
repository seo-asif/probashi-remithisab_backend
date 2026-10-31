<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Models\BusinessSubscription;
use App\Models\PlatformAdmin;
use App\Models\ReferralReward;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentReferralApprovalIntegrationTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private PaymentRequestService $requests;
    private PaymentApprovalService $approvals;
    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = app(PaymentRequestService::class);
        $this->approvals = app(PaymentApprovalService::class);
        $this->referrals = app(ReferralService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_retired_unlimited_approval_does_not_qualify_referral(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Free Inviter',
        ]);

        [$referred, $owner, $referral] =
            $this->claimedReferral($inviter);

        $payment = $this->requests->createUnlimited(
            $referred,
            $owner,
            1,
            'INTEG00001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        try {
            $this->approvals->approve(
                $payment,
                $admin,
            );

            $this->fail(
                'Expected retired Unlimited approval to be rejected.',
            );
        } catch (ApiException $e) {
            $this->assertSame(
                'PLAN_PAYMENT_PRODUCT_RETIRED',
                $e->errorCode,
            );

            $this->assertSame(
                409,
                $e->status,
            );
        }
        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );

        $referral->refresh();

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $inviter->id,
                'access_source' => 'referral',
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

    public function test_manager_pro_purchase_does_not_generate_legacy_referral_discount(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 08:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Paid Inviter',
        ]);

        $paidEndsAt = $now->addMonthsNoOverflow(3);

        BusinessSubscription::create([
            'business_id' => $inviter->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'paid',
            'access_starts_at' => $now->subDay(),
            'access_ends_at' => $paidEndsAt,
            'trial_used_at' => null,
        ]);

        [$referred, $owner, $referral] =
            $this->claimedReferral($inviter);

        $payment = $this->requests->createIndependent(
            $referred,
            $owner,
            PlanPaymentProduct::ManagerPro,
            3,
            'INTEG00002',
            '01812345678',
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

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $referred->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );

        $referral->refresh();

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );

        $subscription = BusinessSubscription::query()
            ->where('business_id', $inviter->id)
            ->firstOrFail();

        $this->assertSame(
            'paid',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                $paidEndsAt,
            ),
        );
    }

    public function test_sender_plus_approval_retry_does_not_create_referral_reward(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 08:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Idempotent Inviter',
        ]);

        [$referred, $owner, $referral] =
            $this->claimedReferral($inviter);

        $payment = $this->requests->createIndependent(
            $referred,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'INTEG00003',
            '01912345678',
        );

        $admin = $this->makePlatformAdmin();

        $first = $this->approvals->approve($payment, $admin);
        $firstAppliedAt = $first->applied_at;

        $subscription = \App\Models\SenderSubscription::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $firstEndsAt = $subscription->access_ends_at;

        CarbonImmutable::setTestNow($now->addDay());

        $second = $this->approvals->approve(
            $payment->refresh(),
            $admin,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $second->status,
        );

        $this->assertTrue(
            $second->applied_at->equalTo($firstAppliedAt),
        );

        $this->assertTrue(
            $subscription->refresh()->access_ends_at->equalTo($firstEndsAt),
        );

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->refresh()->status,
        );

        $this->assertDatabaseCount('referral_rewards', 0);
    }

    public function test_manager_pro_payment_without_referral_approves_normally(): void
    {
        $business = $this->makeBusiness([
            'name' => 'Direct Customer',
        ]);

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::ManagerPro,
            1,
            'INTEG00004',
            '01722345678',
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

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $business->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $business->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_rejected_payment_does_not_qualify_referral(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Rejected Payment Inviter',
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->requests->createUnlimited(
            $referred,
            $owner,
            1,
            'INTEG00005',
            '01612345678',
        );

        $admin = $this->makePlatformAdmin();

        $rejected = $this->approvals->reject(
            $payment,
            $admin,
            'TrxID not found in bKash.',
        );

        $this->assertSame(
            PlanPaymentStatus::Rejected,
            $rejected->status,
        );

        $referral->refresh();

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertNull(
            $referral->qualified_at,
        );

        $this->assertDatabaseMissing(
            'referral_rewards',
            [
                'referral_id' => $referral->id,
            ],
        );
    }

    public function test_claiming_referral_after_payment_approval_does_not_retroactively_qualify(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 08:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Late Inviter',
        ]);

        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Already Paid Friend',
        ]);

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $referred,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'INTEG00006',
            '01512345678',
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $payment,
            $admin,
        );

        /*
         * The referral relationship is created only after the
         * qualifying payment has already been approved.
         */
        CarbonImmutable::setTestNow(
            $now->addMinute(),
        );

        $referral = $this->referrals->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertNull(
            $referral->qualified_at,
        );

        $this->assertDatabaseMissing(
            'referral_rewards',
            [
                'referral_id' => $referral->id,
            ],
        );
    }

    public function test_referral_failure_rolls_back_payment_approval_and_paid_entitlement(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 08:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Rollback Inviter',
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->requests->createUnlimited(
            $referred,
            $owner,
            1,
            'INTEG00007',
            '01312345678',
        );

        /*
         * Referral claim normally granted the referred business its
         * one-time trial.
         *
         * Capture the exact subscription state before approval so we
         * can prove that a failed referral qualification cannot leave
         * the paid entitlement partially applied.
         */
        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $referred->id,
            )
            ->firstOrFail();

        $subscriptionBefore = [
            'tier'             =>
            $subscription->getRawOriginal('tier'),

            'access_source'    =>
            $subscription->getRawOriginal('access_source'),

            'access_starts_at' =>
            $subscription->getRawOriginal('access_starts_at'),

            'access_ends_at'   =>
            $subscription->getRawOriginal('access_ends_at'),

            'trial_used_at'    =>
            $subscription->getRawOriginal('trial_used_at'),
        ];

        /*
         * Deliberately create an impossible pre-existing state:
         *
         * the referral says it is Qualified but has no reward.
         *
         * ReferralQualificationService must reject this state.
         *
         * Importantly, that rejection happens only after
         * PaymentApprovalService has attempted to:
         *
         * 1. activate paid Unlimited;
         * 2. mark the payment Approved/applied.
         *
         * The outer transaction must roll both operations back.
         */
        $referral->forceFill([
            'status'                        =>
            ReferralStatus::Qualified,

            'qualifying_payment_request_id' =>
            $payment->id,

            'qualified_at'                  =>
            $now,

            'qualification_sequence'        =>
            1,
        ])->save();

        $admin = $this->makePlatformAdmin();

        $caught = null;

        try {
            $this->approvals->approve(
                $payment,
                $admin,
            );

            $this->fail(
                'Approval should fail when the qualified referral has no reward.'
            );
        } catch (ApiException $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
        );

        /*
         * Payment approval must have rolled back completely.
         */
        $payment->refresh();

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $payment->status,
        );

        $this->assertNull(
            $payment->decided_by_platform_admin_id,
        );

        $this->assertNull(
            $payment->decision_reason,
        );

        $this->assertNull(
            $payment->decided_at,
        );

        $this->assertNull(
            $payment->applied_at,
        );

        /*
         * PaidAccessService changed this subscription during the failed
         * approval attempt. The transaction must restore every relevant
         * entitlement field to its exact previous value.
         */
        $subscription->refresh();

        $subscriptionAfter = [
            'tier'             =>
            $subscription->getRawOriginal('tier'),

            'access_source'    =>
            $subscription->getRawOriginal('access_source'),

            'access_starts_at' =>
            $subscription->getRawOriginal('access_starts_at'),

            'access_ends_at'   =>
            $subscription->getRawOriginal('access_ends_at'),

            'trial_used_at'    =>
            $subscription->getRawOriginal('trial_used_at'),
        ];

        $this->assertSame(
            $subscriptionBefore,
            $subscriptionAfter,
        );

        /*
         * The failing qualification must not create a partial reward.
         */
        $this->assertDatabaseMissing(
            'referral_rewards',
            [
                'referral_id' => $referral->id,
            ],
        );
    }

    public function test_retrying_legacy_approved_payment_does_not_backfill_existing_referral(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 09:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Legacy Retry Inviter',
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->requests->createUnlimited(
            $referred,
            $owner,
            1,
            'LEGACY0001',
            '01732345678',
        );

        $admin = $this->makePlatformAdmin();

        /*
         * Simulate a payment that was approved by an older version
         * of the application before automatic referral qualification
         * was wired into PaymentApprovalService.
         *
         * The referral already existed, but it was never qualified.
         */
        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $referred->id,
            )
            ->firstOrFail();

        $trialUsedAt = $subscription->trial_used_at;

        $subscription->forceFill([
            'tier'             =>
            PlanLimits::UNLIMITED,

            'access_source'    =>
            'paid',

            'access_starts_at' =>
            $now,

            'access_ends_at'   =>
            $now->addMonthNoOverflow(),

            'trial_used_at'    =>
            $trialUsedAt,
        ])->save();

        $payment->forceFill([
            'status'                       =>
            PlanPaymentStatus::Approved,

            'decided_by_platform_admin_id' =>
            $admin->id,

            'decision_reason'              =>
            null,

            'decided_at'                   =>
            $now,

            'applied_at'                   =>
            $now,
        ])->save();

        /*
         * Retrying an already-approved payment must be read-only.
         *
         * It must not use the retry as an opportunity to qualify
         * an old referral.
         */
        $retried = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertSame(
            PlanPaymentStatus::Approved,
            $retried->status,
        );

        $referral->refresh();

        $this->assertSame(
            ReferralStatus::Claimed,
            $referral->status,
        );

        $this->assertNull(
            $referral->qualifying_payment_request_id,
        );

        $this->assertNull(
            $referral->qualified_at,
        );

        $this->assertDatabaseMissing(
            'referral_rewards',
            [
                'referral_id' => $referral->id,
            ],
        );
    }

    private function claimedReferral(
        $inviter,
    ): array {
        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Referred Friend',
        ]);

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $referral = $this->referrals->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        return [
            $referred,
            $owner,
            $referral,
        ];
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
}
