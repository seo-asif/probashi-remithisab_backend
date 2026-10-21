<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
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

    public function test_available_150_bdt_reward_is_reserved_for_next_unlimited_renewal(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 10:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        /*
         * The reward was earned through the real referral
         * qualification flow and must initially be available.
         */
        $this->assertSame(
            ReferralRewardType::RenewalDiscountBdt,
            $reward->reward_type,
        );

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertSame(
            ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
            $reward->discount_bdt,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull(
            $reward->reserved_at,
        );

        /*
         * This is the inviter's next Unlimited renewal.
         *
         * Exactly one available 150 BDT reward should be
         * reserved for this request.
         */
        $renewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'RENEW00001',
            '01722345678',
        );

        $this->assertSame(
            PlanPackages::priceBdt(1)
             - ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
            $renewal->expected_amount_bdt,
        );

        /*
         * 1-month package:
         *
         * 520 BDT - 150 BDT = 370 BDT.
         */
        $this->assertSame(
            370,
            $renewal->expected_amount_bdt,
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

        $this->assertNotNull(
            $reward->reserved_at,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_at,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            1,
        );
    }

    public function test_approving_discounted_renewal_applies_reserved_reward(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 11:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $renewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            3,
            'APPRV00001',
            '01722345678',
        );

        $this->assertSame(
            1490 - ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
            $renewal->expected_amount_bdt,
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

        $admin = $this->makePlatformAdmin();

        $approved = $this->approvals->approve(
            $renewal,
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

        /*
         * Keep reservation data for the audit trail.
         */
        $this->assertSame(
            $renewal->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNotNull(
            $reward->reserved_at,
        );

        $this->assertSame(
            $renewal->id,
            $reward->applied_to_payment_request_id,
        );

        $this->assertNotNull(
            $reward->applied_at,
        );
    }

    public function test_rejecting_discounted_renewal_releases_reward_for_future_renewal(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $renewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'REJECT0001',
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

        $admin = $this->makePlatformAdmin();

        $rejected = $this->approvals->reject(
            $renewal,
            $admin,
            'TrxID not found in bKash.',
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

        $this->assertNull(
            $reward->reserved_at,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_at,
        );

        /*
         * A rejected renewal did not consume the reward.
         * The next new renewal can reserve it again.
         */
        $nextRenewal = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'RETRY00001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $nextRenewal->expected_amount_bdt,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $nextRenewal->id,
            $reward->reserved_for_payment_request_id,
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

        $friendPayment =
        $this->requests->createUnlimited(
            $friend,
            $friendOwner,
            1,
            'EARN000001',
            '01812345678',
        );

        $platformAdmin =
        $this->makePlatformAdmin();

        $this->approvals->approve(
            $friendPayment,
            $platformAdmin,
        );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

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

    public function test_tampered_discounted_payment_cannot_be_approved(): void
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
            'TAMPER0001',
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

        /*
         * Simulate database corruption/tampering after the legitimate
         * 150 BDT reservation was created.
         */
        $payment->forceFill([
            'expected_amount_bdt' => 369,
        ])->save();

        $admin = $this->makePlatformAdmin();

        $this->expectException(
            \App\Exceptions\ApiException::class,
        );

        $this->approvals->approve(
            $payment,
            $admin,
        );
    }

    public function test_one_reward_discounts_only_one_pending_payment(): void
    {
        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $first = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'FIRST00001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $first->expected_amount_bdt,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $first->id,
            $reward->reserved_for_payment_request_id,
        );

        /*
         * The business has only one earned reward and it is already
         * reserved by the first pending request.
         *
         * The second request must therefore remain full price.
         */
        $second = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'SECOND0001',
            '01722345678',
        );

        $this->assertSame(
            520,
            $second->expected_amount_bdt,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Reserved,
            $reward->status,
        );

        $this->assertSame(
            $first->id,
            $reward->reserved_for_payment_request_id,
        );
    }

    public function test_applied_reward_cannot_be_reused_for_later_renewal(): void
    {
        [
            $inviter,
            $inviterOwner,
            $reward,
        ] = $this->earnRenewalDiscount();

        $first = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'REUSE00001',
            '01722345678',
        );

        $this->assertSame(
            370,
            $first->expected_amount_bdt,
        );

        $admin = $this->makePlatformAdmin();

        $this->approvals->approve(
            $first,
            $admin,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Applied,
            $reward->status,
        );

        $this->assertSame(
            $first->id,
            $reward->applied_to_payment_request_id,
        );

        /*
         * Applied rewards are permanently consumed.
         *
         * With no other Available reward, a later renewal must use
         * the normal server package price.
         */
        $second = $this->requests->createUnlimited(
            $inviter,
            $inviterOwner,
            1,
            'LATER00001',
            '01722345678',
        );

        $this->assertSame(
            520,
            $second->expected_amount_bdt,
        );

        $reward->refresh();

        $this->assertSame(
            ReferralRewardStatus::Applied,
            $reward->status,
        );

        $this->assertSame(
            $first->id,
            $reward->reserved_for_payment_request_id,
        );

        $this->assertSame(
            $first->id,
            $reward->applied_to_payment_request_id,
        );
    }
    public function test_referral_reward_cannot_discount_another_business_payment(): void
{
    [
        $inviter,
        $inviterOwner,
        $reward,
    ] = $this->earnRenewalDiscount();

    /*
     * The inviter legitimately owns one available
     * 150 BDT renewal reward.
     */
    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Available,
        $reward->status,
    );

    $this->assertSame(
        $inviter->id,
        $reward->beneficiary_business_id,
    );

    /*
     * A completely different business submits an
     * Unlimited payment.
     *
     * It must never receive the inviter's reward.
     */
    $otherBusiness = $this->makeBusiness([
        'name' => 'Other Business',
    ]);

    $otherOwner = $this->makeMember(
        $otherBusiness,
        BusinessRole::Owner,
    );

    $otherPayment = $this->requests->createUnlimited(
        $otherBusiness,
        $otherOwner,
        1,
        'CROSS00001',
        '01732345678',
    );

    /*
     * The unrelated business must pay the normal
     * server-authoritative price.
     */
    $this->assertSame(
        PlanPackages::priceBdt(1),
        $otherPayment->expected_amount_bdt,
    );

    $this->assertSame(
        520,
        $otherPayment->expected_amount_bdt,
    );

    /*
     * The inviter's reward must remain untouched.
     */
    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Available,
        $reward->status,
    );

    $this->assertNull(
        $reward->reserved_for_payment_request_id,
    );

    $this->assertNull(
        $reward->reserved_at,
    );

    /*
     * The rightful business must still be able to use it.
     */
    $inviterPayment = $this->requests->createUnlimited(
        $inviter,
        $inviterOwner,
        1,
        'RIGHT00001',
        '01722345678',
    );

    $this->assertSame(
        370,
        $inviterPayment->expected_amount_bdt,
    );

    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Reserved,
        $reward->status,
    );

    $this->assertSame(
        $inviterPayment->id,
        $reward->reserved_for_payment_request_id,
    );

    $this->assertNotSame(
        $otherPayment->id,
        $reward->reserved_for_payment_request_id,
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
            'business_id' =>
                $inviter->id,

            'actor_user_id' =>
                $inviterOwner->id,

            'action' =>
                'referral_reward.reserved',

            'target_type' =>
                'ReferralReward',

            'target_id' =>
                $reward->id,
        ],
    );
}
public function test_referral_discount_application_is_audited(): void
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
        'AUDITRWD02',
        '01722345678',
    );

    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Reserved,
        $reward->status,
    );

    $admin = $this->makePlatformAdmin();

    $this->approvals->approve(
        $renewal,
        $admin,
    );

    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Applied,
        $reward->status,
    );

    $this->assertSame(
        $renewal->id,
        $reward->applied_to_payment_request_id,
    );

    $this->assertDatabaseHas(
        'audit_logs',
        [
            'business_id' =>
                $inviter->id,

            'actor_user_id' =>
                $admin->user_id,

            'action' =>
                'referral_reward.applied',

            'target_type' =>
                'ReferralReward',

            'target_id' =>
                $reward->id,
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
            'business_id' =>
                $inviter->id,

            'actor_user_id' =>
                $admin->user_id,

            'action' =>
                'referral_reward.released',

            'target_type' =>
                'ReferralReward',

            'target_id' =>
                $reward->id,
        ],
    );
}
}
