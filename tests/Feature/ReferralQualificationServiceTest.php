<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentStatus;
use App\Enums\ReferralRewardStatus;
use App\Enums\ReferralRewardType;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessReferral;
use App\Models\BusinessSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Models\ReferralReward;
use App\Models\User;
use App\Support\Plans\PaidAccessService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\ReferralQualificationService;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class ReferralQualificationServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private ReferralQualificationService $qualification;
    private ReferralService $referrals;
    private PaymentRequestService $payments;
    private PaidAccessService $paidAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qualification = app(
            ReferralQualificationService::class,
        );

        $this->referrals = app(
            ReferralService::class,
        );

        $this->payments = app(
            PaymentRequestService::class,
        );

        $this->paidAccess = app(
            PaidAccessService::class,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_referral_qualification_retry_does_not_duplicate_audit_event(): void
{
    $inviter = $this->makeBusiness();

    [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

    $payment = $this->approvedPaidPayment(
        $referred,
        $owner,
        'AUDITQUAL2',
    );

    $this->qualification
        ->qualifyApprovedUnlimitedPayment(
            $payment,
        );

    /*
     * Same qualifying payment is an idempotent retry.
     */
    $this->qualification
        ->qualifyApprovedUnlimitedPayment(
            $payment->refresh(),
        );

    $this->assertSame(
        1,
        \App\Models\AuditLog::query()
            ->where(
                'business_id',
                $inviter->id,
            )
            ->where(
                'action',
                'referral.qualified',
            )
            ->where(
                'target_type',
                'BusinessReferral',
            )
            ->where(
                'target_id',
                $referral->id,
            )
            ->count(),
    );
}
    public function test_free_inviter_receives_immediate_seven_day_unlimited_reward(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-10-21 06:00:00',
                'UTC',
            )
        );

        $inviter = $this->makeBusiness([
            'name' => 'Free Inviter',
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        /* $payment = $this->approvedPaidPayment(
        $referred,
        $owner,
        'RFREE00001',
        );

        $qualified =
        $this->qualification
        ->qualifyApprovedUnlimitedPayment(
        $payment,
        );

         */

        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'RFREE00001',
        );



        $qualified =
        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $this->assertNotNull($qualified);

        $this->assertSame(
            ReferralStatus::Qualified,
            $qualified->status,
        );

        $this->assertSame(
            1,
            $qualified->qualification_sequence,
        );

        $this->assertSame(
            $payment->id,
            $qualified->qualifying_payment_request_id,
        );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralRewardType::UnlimitedDays,
            $reward->reward_type,
        );

        $this->assertSame(
            ReferralRewardStatus::Applied,
            $reward->status,
        );

        $this->assertSame(
            ReferralRules::FREE_REWARD_DAYS,
            $reward->unlimited_days,
        );

        $this->assertNull(
            $reward->discount_bdt,
        );

        $this->assertNotNull(
            $reward->applied_at,
        );

        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $inviter->id,
            )
            ->firstOrFail();

        $this->assertSame(
            PlanLimits::UNLIMITED,
            $subscription->tier,
        );

        $this->assertSame(
            'referral',
            $subscription->access_source,
        );

        $this->assertSame(
            7 * 24 * 60 * 60,
            $subscription->access_ends_at->getTimestamp()
             - $subscription->access_starts_at->getTimestamp(),
        );
    }

    public function test_successful_referral_qualification_is_audited(): void
{
    $inviter = $this->makeBusiness([
        'name' => 'Audit Qualification Inviter',
    ]);

    [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

    $payment = $this->approvedPaidPayment(
        $referred,
        $owner,
        'AUDITQUAL1',
    );

    $qualified = $this->qualification
        ->qualifyApprovedUnlimitedPayment(
            $payment,
        );

    $this->assertNotNull($qualified);

    $admin = PlatformAdmin::query()
        ->findOrFail(
            $payment->decided_by_platform_admin_id,
        );

    $this->assertDatabaseHas(
        'audit_logs',
        [
            /*
             * Qualification creates a reward for the inviter,
             * so the audit event belongs to the inviter business.
             */
            'business_id' => $inviter->id,
            'actor_user_id' => $admin->user_id,
            'action' => 'referral.qualified',
            'target_type' => 'BusinessReferral',
            'target_id' => $referral->id,
        ],
    );
}
    public function test_active_trial_inviter_remains_free_underlying_and_gets_seven_more_days(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 06:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Trial Inviter',
        ]);

        $trialUsedAt = $now->subDays(2);
        $originalEnd = $now->addDays(5);

        BusinessSubscription::create([
            'business_id'      =>
            $inviter->id,

            'tier'             =>
            PlanLimits::UNLIMITED,

            'access_source'    =>
            'trial',

            'access_starts_at' =>
            $trialUsedAt,

            'access_ends_at'   =>
            $originalEnd,

            'trial_used_at'    =>
            $trialUsedAt,
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'RTRIAL0001',
        );

        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $inviter->id,
            )
            ->firstOrFail();

        /*
         * Five existing temporary days + seven reward days.
         */
        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                $originalEnd->addDays(7),
            )
        );

        /*
         * It remains temporary/non-paid access.
         */
        $this->assertSame(
            'trial',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->trial_used_at->equalTo(
                $trialUsedAt,
            )
        );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralRewardType::UnlimitedDays,
            $reward->reward_type,
        );

        $this->assertSame(
            7,
            $reward->unlimited_days,
        );
    }

    public function test_active_paid_unlimited_inviter_receives_150_bdt_future_renewal_reward(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 06:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness([
            'name' => 'Paid Inviter',
        ]);

        $inviterSubscription =
        $this->paidAccess->activate(
            $inviter,
            3,
        );

        $originalEnd =
        $inviterSubscription->access_ends_at;

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'RPAID00001',
        );

        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralRewardType::RenewalDiscountBdt,
            $reward->reward_type,
        );

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertSame(
            150,
            $reward->discount_bdt,
        );

        $this->assertNull(
            $reward->unlimited_days,
        );

        $this->assertNull(
            $reward->reserved_for_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_at,
        );

        $inviterSubscription->refresh();

        /*
         * The discount reward must not change current paid time.
         */
        $this->assertTrue(
            $inviterSubscription->access_ends_at->equalTo(
                $originalEnd,
            )
        );
    }

    public function test_expired_paid_inviter_is_underlying_free_and_receives_seven_days(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 06:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness();

        BusinessSubscription::create([
            'business_id'      =>
            $inviter->id,

            'tier'             =>
            PlanLimits::UNLIMITED,

            'access_source'    =>
            'paid',

            'access_starts_at' =>
            $now->subMonths(2),

            'access_ends_at'   =>
            $now->subMonth(),

            'trial_used_at'    =>
            null,
        ]);

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'REXPD00001',
        );

        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralRewardType::UnlimitedDays,
            $reward->reward_type,
        );

        $subscription = BusinessSubscription::query()
            ->where(
                'business_id',
                $inviter->id,
            )
            ->firstOrFail();

        $this->assertSame(
            'referral',
            $subscription->access_source,
        );

        $this->assertTrue(
            $subscription->access_starts_at->equalTo(
                $now,
            )
        );

        $this->assertTrue(
            $subscription->access_ends_at->equalTo(
                $now->addDays(7),
            )
        );
    }

    public function test_payment_without_referral_is_normal_no_op(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $payment = $this->approvedPaidPayment(
            $business,
            $owner,
            'RNONE00001',
        );

        $result =
        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $this->assertNull($result);

        $this->assertDatabaseCount(
            'business_referrals',
            0,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_pending_payment_cannot_qualify_referral(): void
    {
        $inviter = $this->makeBusiness();

        [$referred, $owner] =
        $this->claimedReferral($inviter);

        $payment = $this->payments->createUnlimited(
            $referred,
            $owner,
            1,
            'RPEND00001',
            '01712345678',
        );

        $this->assertApiException(
            fn() =>
            $this->qualification
                ->qualifyApprovedUnlimitedPayment(
                    $payment,
                ),
            'REFERRAL_PAYMENT_NOT_QUALIFYING',
            409,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_approved_flag_without_applied_paid_entitlement_cannot_qualify(): void
    {
        $inviter = $this->makeBusiness();

        [$referred, $owner] =
        $this->claimedReferral($inviter);

        $payment = $this->payments->createUnlimited(
            $referred,
            $owner,
            1,
            'RFORG00001',
            '01712345678',
        );

        $admin = $this->makePlatformAdmin();

        $now = CarbonImmutable::now('UTC');

        /*
         * Simulate a corrupted/forged approval row.
         * No PaidAccessService activation occurs.
         */
        $payment->forceFill([
            'status'                       =>
            PlanPaymentStatus::Approved,

            'decided_by_platform_admin_id' =>
            $admin->id,

            'decided_at'                   =>
            $now,

            'applied_at'                   =>
            $now,
        ])->save();

        $this->assertApiException(
            fn() =>
            $this->qualification
                ->qualifyApprovedUnlimitedPayment(
                    $payment,
                ),
            'REFERRAL_PAYMENT_NOT_QUALIFYING',
            409,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    public function test_qualifying_same_payment_twice_is_idempotent(): void
    {
        $now = CarbonImmutable::parse(
            '2026-10-21 06:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($now);

        $inviter = $this->makeBusiness();

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'RIDEM00001',
        );

        $first =
        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $inviterSubscription =
        BusinessSubscription::query()
            ->where(
                'business_id',
                $inviter->id,
            )
            ->firstOrFail();

        $firstEnd =
        $inviterSubscription->access_ends_at;

        $second =
        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $inviterSubscription->refresh();

        $this->assertSame(
            $first->id,
            $second->id,
        );

        $this->assertSame(
            $referral->id,
            $second->id,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            1,
        );

        $this->assertTrue(
            $inviterSubscription->access_ends_at->equalTo(
                $firstEnd,
            )
        );
    }

    public function test_later_paid_renewal_does_not_create_second_reward_for_same_friend(): void
    {
        $inviter = $this->makeBusiness();

        [$referred, $owner, $referral] =
        $this->claimedReferral($inviter);

        $firstPayment =
        $this->approvedPaidPayment(
            $referred,
            $owner,
            'RFIRST0001',
        );

        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $firstPayment,
            );

        $secondPayment =
        $this->approvedPaidPayment(
            $referred,
            $owner,
            'RSECOND001',
        );

        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $secondPayment,
            );

        $referral->refresh();

        $this->assertSame(
            ReferralStatus::Qualified,
            $referral->status,
        );

        $this->assertSame(
            $firstPayment->id,
            $referral->qualifying_payment_request_id,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            1,
        );
    }

    public function test_only_first_five_friends_can_qualify_for_one_inviter(): void
    {
        $inviter = $this->makeBusiness([
            'name' => 'Five Friend Inviter',
        ]);

        $qualifiedIds = [];

        for ($i = 1; $i <= 6; $i++) {
            [$referred, $owner, $referral] =
            $this->claimedReferral(
                $inviter,
                "Friend {$i}",
            );

            $payment =
            $this->approvedPaidPayment(
                $referred,
                $owner,
                sprintf(
                    'RFMAX%05d',
                    $i,
                ),
            );

            $result =
            $this->qualification
                ->qualifyApprovedUnlimitedPayment(
                    $payment,
                );

            $result->refresh();

            if ($i <= 5) {
                $this->assertSame(
                    ReferralStatus::Qualified,
                    $result->status,
                );

                $this->assertSame(
                    $i,
                    $result->qualification_sequence,
                );

                $qualifiedIds[] = $result->id;
            } else {
                /*
                 * Sixth friend's paid Unlimited is still valid,
                 * but the referral reward lifetime limit is full.
                 */
                $this->assertSame(
                    ReferralStatus::Claimed,
                    $result->status,
                );

                $this->assertNull(
                    $result->qualification_sequence,
                );

                $this->assertNull(
                    $result->qualifying_payment_request_id,
                );

                $this->assertDatabaseMissing(
                    'referral_rewards',
                    [
                        'referral_id' =>
                        $referral->id,
                    ],
                );
            }
        }

        $this->assertCount(
            5,
            $qualifiedIds,
        );

        $this->assertSame(
            5,
            BusinessReferral::query()
                ->where(
                    'inviter_business_id',
                    $inviter->id,
                )
                ->where(
                    'status',
                    ReferralStatus::Qualified->value,
                )
                ->count(),
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            5,
        );
    }

    public function test_referral_claimed_after_payment_approval_cannot_qualify_retroactively(): void
    {
        $firstTime = CarbonImmutable::parse(
            '2026-10-21 06:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow(
            $firstTime,
        );

        $inviter = $this->makeBusiness();

        $referred = $this->makeBusiness();

        $owner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        /*
         * Friend pays and the payment is already approved.
         */
        $payment = $this->approvedPaidPayment(
            $referred,
            $owner,
            'RLATE00001',
        );

        /*
         * Invite is claimed only after that approval.
         */
        CarbonImmutable::setTestNow(
            $firstTime->addHour(),
        );

        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referral = $this->referrals->claim(
            $referred,
            $owner,
            $profile->invite_code,
        );

        $result =
        $this->qualification
            ->qualifyApprovedUnlimitedPayment(
                $payment,
            );

        $this->assertSame(
            $referral->id,
            $result->id,
        );

        $this->assertSame(
            ReferralStatus::Claimed,
            $result->status,
        );

        $this->assertNull(
            $result->qualified_at,
        );

        $this->assertNull(
            $result->qualifying_payment_request_id,
        );

        $this->assertDatabaseCount(
            'referral_rewards',
            0,
        );
    }

    private function claimedReferral(
        Business $inviter,
        string $friendName = 'Referred Friend',
    ): array {
        $profile = $this->referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => $friendName,
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

    private function approvedPaidPayment(
        Business $business,
        User $owner,
        string $trxId,
        int $months = 1,
    ): PlanPaymentRequest {
        $payment = $this->payments->createUnlimited(
            $business,
            $owner,
            $months,
            $trxId,
            '01712345678',
        );

        /*
         * Apply the real paid entitlement first.
         */
        $this->paidAccess->activate(
            $business,
            $months,
        );

        $admin = $this->makePlatformAdmin();

        $now = CarbonImmutable::now('UTC');

        /*
         * Reproduce the durable state that PaymentApprovalService
         * records after successful platform-admin approval.
         *
         * Phase 4F.4 will connect these two services directly.
         */
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

        return $payment->refresh();
    }

    private function makePlatformAdmin(): PlatformAdmin
    {
        $user = User::factory()->create();

        return PlatformAdmin::create([
            'user_id' =>
            $user->id,

            'status'  =>
            AccountStatus::Active,
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
            "Expected ApiException {$code} was not thrown."
        );
    }
}
