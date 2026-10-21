<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProvider;
use App\Enums\ReferralRewardStatus;
use App\Models\BusinessSubscription;
use App\Models\PlatformAdmin;
use App\Models\PlatformPaymentProviderSetting;
use App\Models\ReferralReward;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use App\Support\Plans\PlanLimits;
use App\Support\Plans\ReferralRules;
use App\Support\Plans\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentOptionsApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_payment_options(): void
    {
        $this->getJson('/api/v1/payments/options')
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );
    }

    public function test_read_only_staff_cannot_view_payment_options(): void
    {
        [, $user] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/payments/options')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLAN_PAYMENT_FORBIDDEN',
            );
    }

    public function test_owner_sees_server_packages_and_disabled_provider(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.payment_method.provider',
                'manual_bkash',
            )
            ->assertJsonPath(
                'data.payment_method.available',
                false,
            )
            ->assertJsonPath(
                'data.payment_method.bkash_number',
                null,
            )
            ->assertJsonPath(
                'data.referral_discount.available',
                false,
            )
            ->assertJsonPath(
                'data.referral_discount.amount_bdt',
                0,
            )
            ->assertJsonPath(
                'data.pending_requests.count',
                0,
            )
            ->assertJsonPath(
                'data.pending_requests.limit',
                2,
            )
            ->assertJsonPath(
                'data.pending_requests.can_submit',
                false,
            );

        $packages = $response->json('data.packages');

        $this->assertSame(
            [
                [
                    'months' => 1,
                    'base_amount_bdt' => 520,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => 520,
                ],
                [
                    'months' => 3,
                    'base_amount_bdt' => 1490,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => 1490,
                ],
                [
                    'months' => 6,
                    'base_amount_bdt' => 2790,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => 2790,
                ],
                [
                    'months' => 12,
                    'base_amount_bdt' => 5200,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => 5200,
                ],
            ],
            $packages,
        );
    }

    public function test_enabled_manual_bkash_is_exposed_to_owner(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        $this->enableManualBkash();

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath(
                'data.payment_method.provider',
                'manual_bkash',
            )
            ->assertJsonPath(
                'data.payment_method.available',
                true,
            )
            ->assertJsonPath(
                'data.payment_method.bkash_number',
                '01712345678',
            )
            ->assertJsonPath(
                'data.pending_requests.can_submit',
                true,
            );
    }

    public function test_business_administrator_can_view_payment_options(): void
    {
        [, $administrator] =
            $this->makeAuthorizedMember(
                BusinessRole::Administrator,
            );

        $this->enableManualBkash();

        Sanctum::actingAs($administrator);

        $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath(
                'data.payment_method.available',
                true,
            )
            ->assertJsonPath(
                'data.payment_method.bkash_number',
                '01712345678',
            );
    }

    public function test_available_referral_reward_previews_150_bdt_discount_without_reserving_it(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $this->enableManualBkash();

        $reward = $this->earnRenewalDiscount(
            $business,
        );

        Sanctum::actingAs($owner);

        $response = $this
            ->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath(
                'data.referral_discount.available',
                true,
            )
            ->assertJsonPath(
                'data.referral_discount.amount_bdt',
                150,
            );

        $packages = $response->json('data.packages');

        $this->assertSame(
            [
                [
                    'months' => 1,
                    'base_amount_bdt' => 520,
                    'discount_bdt' => 150,
                    'payable_amount_bdt' => 370,
                ],
                [
                    'months' => 3,
                    'base_amount_bdt' => 1490,
                    'discount_bdt' => 150,
                    'payable_amount_bdt' => 1340,
                ],
                [
                    'months' => 6,
                    'base_amount_bdt' => 2790,
                    'discount_bdt' => 150,
                    'payable_amount_bdt' => 2640,
                ],
                [
                    'months' => 12,
                    'base_amount_bdt' => 5200,
                    'discount_bdt' => 150,
                    'payable_amount_bdt' => 5050,
                ],
            ],
            $packages,
        );

        /*
         * GET /payments/options is preview-only.
         * It must not reserve or consume the reward.
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

        $this->assertNull(
            $reward->applied_to_payment_request_id,
        );

        $this->assertNull(
            $reward->applied_at,
        );
    }

    public function test_two_pending_requests_disable_new_payment_in_options(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $this->enableManualBkash();

        $payments = app(
            PaymentRequestService::class,
        );

        $payments->createUnlimited(
            $business,
            $owner,
            1,
            'OPTCOUNT01',
            '01712345678',
        );

        $payments->createUnlimited(
            $business,
            $owner,
            3,
            'OPTCOUNT02',
            '01812345678',
        );

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath(
                'data.pending_requests.count',
                2,
            )
            ->assertJsonPath(
                'data.pending_requests.limit',
                2,
            )
            ->assertJsonPath(
                'data.pending_requests.can_submit',
                false,
            );
    }

    public function test_pending_count_is_isolated_to_current_business(): void
    {
        [$business, $owner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        [$otherBusiness, $otherOwner] =
            $this->makeAuthorizedMember(
                BusinessRole::Owner,
            );

        $this->enableManualBkash();

        $payments = app(
            PaymentRequestService::class,
        );

        $payments->createUnlimited(
            $otherBusiness,
            $otherOwner,
            1,
            'OTHEROPT01',
            '01712345678',
        );

        $payments->createUnlimited(
            $otherBusiness,
            $otherOwner,
            3,
            'OTHEROPT02',
            '01812345678',
        );

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath(
                'data.pending_requests.count',
                0,
            )
            ->assertJsonPath(
                'data.pending_requests.limit',
                2,
            )
            ->assertJsonPath(
                'data.pending_requests.can_submit',
                true,
            );

        $this->assertNotSame(
            $business->id,
            $otherBusiness->id,
        );
    }

    public function test_disabled_provider_does_not_expose_saved_bkash_number(): void
{
    [, $owner] = $this->makeAuthorizedMember(
        BusinessRole::Owner,
    );

    $this->enableManualBkash();

    $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                PlanPaymentProvider::ManualBkash->value,
            )
            ->firstOrFail();

    /*
     * Platform Admin may disable manual bKash while preserving
     * the saved receiving number for later re-enabling.
     *
     * Customers must not receive that number while disabled.
     */
    $setting->forceFill([
        'is_enabled' => false,
    ])->save();

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/payments/options')
        ->assertOk()
        ->assertJsonPath(
            'data.payment_method.available',
            false,
        )
        ->assertJsonPath(
            'data.payment_method.bkash_number',
            null,
        )
        ->assertJsonPath(
            'data.pending_requests.can_submit',
            false,
        );
}

public function test_discount_preview_matches_authoritative_post_and_reward_is_reserved(): void
{
    [$business, $owner] =
        $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

    $this->enableManualBkash();

    $reward = $this->earnRenewalDiscount(
        $business,
    );

    Sanctum::actingAs($owner);

    /*
     * GET is only a preview.
     */
    $this->getJson('/api/v1/payments/options')
        ->assertOk()
        ->assertJsonPath(
            'data.referral_discount.available',
            true,
        )
        ->assertJsonPath(
            'data.referral_discount.amount_bdt',
            150,
        )
        ->assertJsonPath(
            'data.packages.1.months',
            3,
        )
        ->assertJsonPath(
            'data.packages.1.base_amount_bdt',
            1490,
        )
        ->assertJsonPath(
            'data.packages.1.payable_amount_bdt',
            1340,
        );

    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Available,
        $reward->status,
    );

    /*
     * POST remains authoritative.
     *
     * PaymentRequestService independently calculates the server
     * package price and atomically reserves the available reward.
     */
    $response = $this->postJson(
        '/api/v1/payments',
        [
            'package_months' => 3,
            'trx_id' => 'OPTPOST001',
            'payer_number' => '01712345678',
        ],
    )
        ->assertCreated()
        ->assertJsonPath(
            'data.package_months',
            3,
        )
        ->assertJsonPath(
            'data.amount_bdt',
            1340,
        )
        ->assertJsonPath(
            'data.status',
            'pending',
        );

    $paymentId = $response->json('data.id');

    $reward->refresh();

    $this->assertSame(
        ReferralRewardStatus::Reserved,
        $reward->status,
    );

    $this->assertNotNull(
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

    /*
     * Once the only available reward is reserved, a second payment
     * must not be advertised as discounted.
     */
    $this->getJson('/api/v1/payments/options')
        ->assertOk()
        ->assertJsonPath(
            'data.referral_discount.available',
            false,
        )
        ->assertJsonPath(
            'data.referral_discount.amount_bdt',
            0,
        )
        ->assertJsonPath(
            'data.packages.1.payable_amount_bdt',
            1490,
        );

    $this->assertNotEmpty($paymentId);
}



    private function enableManualBkash(): void
    {
        $setting =
            PlatformPaymentProviderSetting::query()
                ->where(
                    'provider',
                    PlanPaymentProvider::ManualBkash->value,
                )
                ->firstOrFail();

        $setting->forceFill([
            'is_enabled' => true,
            'public_account_identifier' =>
                '01712345678',
        ])->save();
    }

    private function earnRenewalDiscount(
        $inviter,
    ): ReferralReward {
        /*
         * A paid Unlimited inviter earns the 150 BDT reward
         * only after a referred friend's paid Unlimited request
         * is approved.
         */
        BusinessSubscription::query()->create([
            'business_id' => $inviter->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'paid',
            'access_starts_at' =>
                now('UTC')->subDay(),
            'access_ends_at' =>
                now('UTC')->addMonths(3),
            'trial_used_at' => null,
        ]);

        $referrals = app(
            ReferralService::class,
        );

        $profile = $referrals->profileFor(
            $inviter,
        );

        $referred = $this->makeBusiness([
            'name' => 'Payment Options Friend',
        ]);

        $referredOwner = $this->makeMember(
            $referred,
            BusinessRole::Owner,
        );

        $referral = $referrals->claim(
            $referred,
            $referredOwner,
            $profile->invite_code,
        );

        $payment = app(
            PaymentRequestService::class,
        )->createUnlimited(
            $referred,
            $referredOwner,
            1,
            'OPTREW0001',
            '01912345678',
        );

        app(PaymentApprovalService::class)
            ->approve(
                $payment,
                $this->makePlatformAdmin(),
            );

        $reward = ReferralReward::query()
            ->where(
                'referral_id',
                $referral->id,
            )
            ->firstOrFail();

        $this->assertSame(
            ReferralRewardStatus::Available,
            $reward->status,
        );

        $this->assertSame(
            ReferralRules::PAID_RENEWAL_DISCOUNT_BDT,
            $reward->discount_bdt,
        );

        return $reward;
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

    private function activateUser($user): void
    {
        $user->forceFill([
            'status' => 'active',
            'email_verified_at' => now(),
        ])->save();
    }

    private function makePlatformAdmin(
        AccountStatus $status =
            AccountStatus::Active,
    ): PlatformAdmin {
        $user = User::factory()->create();

        return PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }
}