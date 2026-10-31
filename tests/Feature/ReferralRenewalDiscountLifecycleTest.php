<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Enums\ReferralStatus;
use App\Models\BusinessSubscription;
use App\Models\PlatformAdmin;
use App\Models\ReferralReward;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\PlanPackages;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class ReferralRenewalDiscountLifecycleTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private PaymentRequestService $requests;
    private PaymentApprovalService $approvals;
    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = app(
            PaymentRequestService::class,
        );

        $this->approvals = app(
            PaymentApprovalService::class,
        );

        $this->referrals = app(
            ReferralService::class,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_historical_reward_is_not_reserved_for_manager_pro(): void
    {
        $inviter = $this->makeBusiness();
        $owner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($inviter);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        $referral->forceFill([
            'status' => \App\Enums\ReferralStatus::Qualified,
            'qualified_at' => now('UTC'),
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'beneficiary_business_id' => $inviter->id,
            'reward_type' => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days' => null,
            'discount_bdt' => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        $payment = $this->requests->createIndependent(
            $inviter,
            $owner,
            \App\Enums\PlanPaymentProduct::ManagerPro,
            1,
            'HISTMGR001',
            '01712345678',
        );

        $this->assertSame(
            299,
            $payment->expected_amount_bdt,
        );

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->refresh()->status,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $payment,
            $admin,
        );

        $this->assertSame(
            \App\Enums\PlanPaymentStatus::Approved,
            $approved->status,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_at,
        );

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $inviter->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_retired_discounted_unlimited_approval_preserves_reserved_reward(): void
    {
        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $payment = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'APPRV00001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $payment->expected_amount_bdt,
        );

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->refresh()->status,
        );

        $this->assertSame(
            $payment->id,
            $reward->reserved_for_payment_request_id,
        );

        $admin = $this->makePlatformAdmin();

        try {
            $this->approvals->approve($payment, $admin);

            $this->fail(
                'Retired Unlimited payment was approved.',
            );
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(
                'PLAN_PAYMENT_PRODUCT_RETIRED',
                $e->errorCode,
            );

            $this->assertSame(409, $e->status);
        }

        $this->assertSame(
            \App\Enums\PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );

        $this->assertNull($payment->applied_at);

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $payment->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull($reward->applied_at);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'referral_reward.applied',
            'target_type' => 'ReferralReward',
            'target_id' => $reward->id,
        ]);
    }

    public function test_rejecting_historical_discounted_payment_releases_reward(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness();
        $owner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($inviter);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        $referral->forceFill([
            'status' => \App\Enums\ReferralStatus::Qualified,
            'qualified_at' => $now,
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'beneficiary_business_id' => $inviter->id,
            'reward_type' => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days' => null,
            'discount_bdt' => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        // Simulate an existing pending legacy payment.
        $payment = $this->requests->createUnlimited(
            $inviter,
            $owner,
            1,
            'REJECT0001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $payment->expected_amount_bdt,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $payment->id,
            $reward->reserved_for_payment_request_id,
        );

        $admin = $this->makePlatformAdmin();

        $rejected = $this->approvals->reject(
            $payment,
            $admin,
            'Payment could not be verified.',
        );

        $this->assertSame(
            \App\Enums\PlanPaymentStatus::Rejected,
            $rejected->status,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull($reward->reserved_at);
        $this->assertNull($reward->applied_to_payment_request_id);
        $this->assertNull($reward->applied_at);

        // Repeating rejection must not change the reward.
        $this->approvals->reject(
            $payment->refresh(),
            $admin,
            'Repeated rejection.',
        );

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->refresh()->status,
        );

        $this->assertDatabaseMissing(
            'business_subscriptions',
            [
                'business_id' => $inviter->id,
                'access_source' => 'paid',
            ],
        );
    }

    /**
     * Earn a real 150 BDT referral reward.
     *
     * The inviter starts as active paid Unlimited.
     * A friend claims the invite, buys Unlimited, and a platform
     * administrator approves that payment.
     *
     * Referral qualification must then create the inviter's
     * available 150 BDT renewal reward.
     */
    private function earnRenewalDiscount(): array
    {
        $now = CarbonImmutable::now('UTC');

        $inviter = $this->makeBusiness([
            'name' => 'Paid Referral Inviter',
        ]);

        $inviterOwner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        BusinessSubscription::create([
            'business_id'      =>
            $inviter->id,

            'tier'             =>
            PlanLimits::UNLIMITED,

            'access_source'    =>
            'paid',

            'access_starts_at' =>
            $now->subDay(),

            'access_ends_at'   =>
            $now->addMonthsNoOverflow(3),

            'trial_used_at'    =>
            null,
        ]);

        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $friend = $this->makeBusiness([
            'name' => 'Qualifying Friend',
        ]);

        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        /*
         * Reconstruct an existing historical qualification.
         *
         * New Unlimited approvals are retired. This fixture
         * must not create or approve a new Unlimited payment.
         */
        $referral->forceFill([
            'status' =>
                \App\Enums\ReferralStatus::Qualified,

            'qualified_at' =>
                $now,

            'qualification_sequence' =>
                1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' =>
                $referral->id,

            'beneficiary_business_id' =>
                $inviter->id,

            'reward_type' =>
                ReferralRewardType::RenewalDiscountBdt,

            'unlimited_days' =>
                null,

            'discount_bdt' =>
                ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        return [
            $inviter,
            $inviterOwner,
            $reward,
        ];
    }

    private function makePlatformAdmin(
        AccountStatus $status = AccountStatus::Active,
    ): PlatformAdmin {
        $user = User::factory()->create();

        return PlatformAdmin::create([
            'user_id' =>
            $user->id,

            'status'  =>
            $status,
        ]);
    }

    public function test_tampered_sender_plus_price_cannot_be_approved(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->requests->createIndependent(
            $business,
            $owner,
            \App\Enums\PlanPaymentProduct::SenderPlus,
            1,
            'TAMPER0001',
            '01722345678',
        );

        $this->assertSame(
            99,
            $payment->expected_amount_bdt,
        );

        $payment->forceFill([
            'expected_amount_bdt' => 98,
        ])->save();

        $admin = $this->makePlatformAdmin();

        try {
            $this->approvals->approve(
                $payment,
                $admin,
            );

            $this->fail(
                'Tampered Sender Plus price was approved.',
            );
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(
            \App\Enums\PlanPaymentStatus::Pending,
            $payment->refresh()->status,
        );

        $this->assertNull($payment->applied_at);

        $this->assertDatabaseMissing(
            'sender_subscriptions',
            [
                'user_id' => $owner->id,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_historical_reserved_reward_is_not_consumed_by_sender_plus(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($business);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        $referral->forceFill([
            'status' => \App\Enums\ReferralStatus::Qualified,
            'qualified_at' => now('UTC'),
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'beneficiary_business_id' => $business->id,
            'reward_type' => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days' => null,
            'discount_bdt' => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        // Existing legacy pending payment reserves the reward.
        $legacyPayment = $this->requests->createUnlimited(
            $business,
            $owner,
            1,
            'FIRST00001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $legacyPayment->expected_amount_bdt,
        );

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->refresh()->status,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->reserved_for_payment_request_id,
        );

        // Sender Plus must use its independent full price.
        $senderPayment = $this->requests->createIndependent(
            $business,
            $owner,
            \App\Enums\PlanPaymentProduct::SenderPlus,
            1,
            'SECOND0001',
            '01722345678',
        );

        $this->assertSame(
            99,
            $senderPayment->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $senderPayment,
            $admin,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNotSame(
            $senderPayment->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertDatabaseHas(
            'sender_subscriptions',
            [
                'user_id' => $owner->id,
                'tier' => PlanLimits::SENDER_PLUS,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_historical_applied_reward_cannot_be_reused_by_sender_plus(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 10:00:00',
            'UTC',
        );
        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness();
        $owner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($inviter);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        // Historical legacy payment, created before the reward.
        $legacyPayment = $this->requests->createUnlimited(
            $inviter,
            $owner,
            1,
            'REUSE00001',
            '01722345678',
        );

        $admin = $this->makePlatformAdmin();

        // Represent an approval that occurred before retirement.
        $legacyPayment->forceFill([
            'status' => \App\Enums\PlanPaymentStatus::Approved,
            'expected_amount_bdt' => 370,
            'decided_by_platform_admin_id' => $admin->id,
            'decided_at' => $now,
            'applied_at' => $now,
        ])->save();

        $referral->forceFill([
            'status' => \App\Enums\ReferralStatus::Qualified,
            'qualified_at' => $now,
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'beneficiary_business_id' => $inviter->id,
            'reward_type' => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days' => null,
            'discount_bdt' => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        $reward->forceFill([
            'status' => ReferralRewardStatus::Applied,
            'reserved_for_payment_request_id' => $legacyPayment->id,
            'reserved_at' => $now,
            'applied_to_payment_request_id' => $legacyPayment->id,
            'applied_at' => $now,
        ])->save();

        $payment = $this->requests->createIndependent(
            $inviter,
            $owner,
            \App\Enums\PlanPaymentProduct::SenderPlus,
            1,
            'LATER00001',
            '01722345678',
        );

        $this->assertSame(
            99,
            $payment->expected_amount_bdt,
        );

        $this->approvals->approve($payment, $admin);

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Applied,
            $reward->status,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->applied_to_payment_request_id,
        );

        $this->assertTrue(
            $reward->applied_at->equalTo($now),
        );

        $this->assertDatabaseHas(
            'sender_subscriptions',
            [
                'user_id' => $owner->id,
                'tier' => PlanLimits::SENDER_PLUS,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_historical_reward_cannot_discount_another_business_manager_pro(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Reward Owner Business',
        ]);

        $inviterOwner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($inviter);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        $referral->forceFill([
            'status' => \App\Enums\ReferralStatus::Qualified,
            'qualified_at' => now('UTC'),
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'beneficiary_business_id' => $inviter->id,
            'reward_type' => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days' => null,
            'discount_bdt' => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        $otherBusiness = $this->makeBusiness([
            'name' => 'Other Business',
        ]);

        $otherOwner = $this->makeMember(
            $otherBusiness,
            BusinessRole::Owner,
        );

        $otherPayment = $this->requests->createIndependent(
            $otherBusiness,
            $otherOwner,
            \App\Enums\PlanPaymentProduct::ManagerPro,
            1,
            'CROSS00001',
            '01732345678',
        );

        $this->assertSame(
            299,
            $otherPayment->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $otherPayment,
            $admin,
        );

        $reward->refresh();

        $this->assertSame(
            $inviter->id,
            $reward->beneficiary_business_id,
        );

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull($reward->reserved_at);
        $this->assertNull($reward->applied_to_payment_request_id);
        $this->assertNull($reward->applied_at);

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $otherBusiness->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );

        // Even the rightful reward owner cannot apply an old
        // Unlimited referral discount to Manager Pro.
        $inviterPayment = $this->requests->createIndependent(
            $inviter,
            $inviterOwner,
            \App\Enums\PlanPaymentProduct::ManagerPro,
            1,
            'RIGHT00001',
            '01722345678',
        );

        $this->assertSame(
            299,
            $inviterPayment->expected_amount_bdt,
        );

        $this->approvals->approve(
            $inviterPayment,
            $admin,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertSame(
            $inviter->id,
            $reward->beneficiary_business_id,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $inviter->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            1,
        );
    }

    public function test_referral_discount_reservation_is_audited(): void
    {
        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $renewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'AUDITRWD01',
            '01722345678',
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $renewal->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'business_id'   =>
                $inviter->id,

                'actor_user_id' =>
                $inviterOwner->id,

                'action'        =>
                'referral_reward.reserved',

                'target_type'   =>
                'ReferralReward',

                'target_id'     =>
                $reward->id,
            ],
        );
    }

    public function test_historical_application_audit_survives_manager_pro_approval(): void
    {
        [
            $inviter,
            $owner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $legacyPayment = $this->requests->createUnlimited(
            $inviter,
            $owner,
            1,
            'AUDITRWD02',
            '01722345678',
        );

        $this->assertSame(
            370,
            $legacyPayment->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();
        $now = now('UTC');

        /*
         * Reconstruct an approval that happened before
         * Unlimited approvals were retired.
         */
        $legacyPayment->forceFill([
            'status' =>
                \App\Enums\PlanPaymentStatus::Approved,
            'decided_by_platform_admin_id' =>
                $admin->id,
            'decided_at' =>
                $now,
            'applied_at' =>
                $now,
        ])->save();

        $reward->forceFill([
            'status' =>
                ReferralRewardStatus::Applied,
            'applied_to_payment_request_id' =>
                $legacyPayment->id,
            'applied_at' =>
                $now,
        ])->save();

        $historicalAudit = \App\Models\AuditLog::create([
            'business_id' => $inviter->id,
            'actor_user_id' => $admin->user_id,
            'action' => 'referral_reward.applied',
            'target_type' => 'ReferralReward',
            'target_id' => $reward->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $historicalAudit->id,
            'business_id' => $inviter->id,
            'actor_user_id' => $admin->user_id,
            'action' => 'referral_reward.applied',
            'target_type' => 'ReferralReward',
            'target_id' => $reward->id,
        ]);

        $managerPayment = $this->requests->createIndependent(
            $inviter,
            $owner,
            \App\Enums\PlanPaymentProduct::ManagerPro,
            1,
            'AUDITMGR01',
            '01722345678',
        );

        $this->assertSame(
            299,
            $managerPayment->expected_amount_bdt,
        );

        $approved = $this->approvals->approve(
            $managerPayment,
            $admin,
        );

        $this->assertSame(
            \App\Enums\PlanPaymentStatus::Approved,
            $approved->status,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Applied,
            $reward->status,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertSame(
            $legacyPayment->id,
            $reward->applied_to_payment_request_id,
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $historicalAudit->id,
            'business_id' => $inviter->id,
            'actor_user_id' => $admin->user_id,
            'action' => 'referral_reward.applied',
            'target_type' => 'ReferralReward',
            'target_id' => $reward->id,
        ]);

        $this->assertSame(
            1,
            \App\Models\AuditLog::query()
                ->where('action', 'referral_reward.applied')
                ->where('target_type', 'ReferralReward')
                ->where('target_id', $reward->id)
                ->count(),
        );

        $this->assertDatabaseHas(
            'manager_subscriptions',
            [
                'business_id' => $inviter->id,
                'tier' => PlanLimits::MANAGER_PRO,
                'access_source' => 'paid',
            ],
        );
    }

    public function test_referral_discount_release_is_audited(): void
    {
        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $renewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'AUDITRWD03',
            '01722345678',
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->reject(
            $renewal,
            $admin,
            'Payment could not be verified.',
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'business_id'   =>
                $inviter->id,

                'actor_user_id' =>
                $admin->user_id,

                'action'        =>
                'referral_reward.released',

                'target_type'   =>
                'ReferralReward',

                'target_id'     =>
                $reward->id,
            ],
        );
    }

    public function test_historical_referral_reward_cannot_discount_sender_plus(): void
    {
        $inviter = $this->makeBusiness();
        $owner = $this->makeMember(
            $inviter,
            BusinessRole::Owner,
        );

        $friend = $this->makeBusiness();
        $friendOwner = $this->makeMember(
            $friend,
            BusinessRole::Owner,
        );

        $profile = $this->referrals->profileFor($inviter);

        $referral = $this->referrals->claim(
            $friend,
            $friendOwner,
            $profile->invite_code,
        );

        $referral->forceFill([
            'status'                 => ReferralStatus::Qualified,
            'qualified_at'           => now('UTC'),
            'qualification_sequence' => 1,
        ])->save();

        $reward = ReferralReward::create([
            'referral_id'             => $referral->id,
            'beneficiary_business_id' => $inviter->id,
            'reward_type'             => ReferralRewardType::RenewalDiscountBdt,
            'unlimited_days'          => null,
            'discount_bdt'            => ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
        ]);

        $payment = $this->requests->createIndependent(
            $inviter,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'HISTDISC01',
            '01712345678',
        );

        $this->assertSame(99, $payment->expected_amount_bdt);

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->refresh()->status,
        );

        $this->assertNull($reward->reserved_for_payment_request_id);
        $this->assertNull($reward->applied_to_payment_request_id);

        $admin = $this->makePlatformAdmin();
        $this->approvals->approve($payment, $admin);

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->refresh()->status,
        );

        $this->assertNull($reward->applied_at);

        $this->assertDatabaseHas('sender_subscriptions', [
            'user_id'       => $owner->id,
            'tier'          => PlanLimits::SENDER_PLUS,
            'access_source' => 'paid',
        ]);
    }
}
