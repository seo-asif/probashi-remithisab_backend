<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentProvider;
use App\Models\PlatformAdmin;
use App\Models\PlatformPaymentProviderSetting;
use App\Models\User;
use App\Support\Plans\PaymentRequestService;
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

        $expected = [];

        foreach ([
            'sender_plus' => [1 => 99, 3 => 297, 6 => 594, 12 => 1188],
            'manager_pro' => [1 => 299, 3 => 897, 6 => 1794, 12 => 3588],
        ] as $product => $prices) {
            foreach ($prices as $months => $amount) {
                $expected[] = [
                    'product' => $product,
                    'months' => $months,
                    'base_amount_bdt' => $amount,
                    'discount_bdt' => 0,
                    'payable_amount_bdt' => $amount,
                ];
            }
        }

        $this->assertSame($expected, $packages);
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

    public function test_legacy_referral_reward_does_not_discount_new_packages(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        $this->enableManualBkash();
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath('data.referral_discount.available', false)
            ->assertJsonPath('data.referral_discount.amount_bdt', 0);

        $packages = $response->json('data.packages');

        $this->assertCount(8, $packages);

        foreach ($packages as $package) {
            $this->assertSame(0, $package['discount_bdt']);
            $this->assertSame(
                $package['base_amount_bdt'],
                $package['payable_amount_bdt'],
            );
        }
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

        $payments->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
            1,
            'OPTCOUNT01',
            '01712345678',
        );

        $payments->createIndependent(
            $business,
            $owner,
            PlanPaymentProduct::SenderPlus,
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

        $payments->createIndependent(
            $otherBusiness,
            $otherOwner,
            PlanPaymentProduct::SenderPlus,
            1,
            'OTHEROPT01',
            '01712345678',
        );

        $payments->createIndependent(
            $otherBusiness,
            $otherOwner,
            PlanPaymentProduct::SenderPlus,
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

    public function test_new_payment_uses_full_price_without_referral_discount(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        $this->enableManualBkash();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/payments/options')
            ->assertOk()
            ->assertJsonPath('data.referral_discount.available', false)
            ->assertJsonPath('data.referral_discount.amount_bdt', 0)
            ->assertJsonPath('data.packages.1.product', 'sender_plus')
            ->assertJsonPath('data.packages.1.months', 3)
            ->assertJsonPath('data.packages.1.base_amount_bdt', 297)
            ->assertJsonPath('data.packages.1.discount_bdt', 0)
            ->assertJsonPath('data.packages.1.payable_amount_bdt', 297);

        $this->postJson('/api/v1/payments', [
            'product' => 'sender_plus',
            'package_months' => 3,
            'trx_id' => 'OPTPOST001',
            'payer_number' => '01712345678',
            'referral_discount_bdt' => 150,
        ])
            ->assertCreated()
            ->assertJsonPath('data.product', 'sender_plus')
            ->assertJsonPath('data.package_months', 3)
            ->assertJsonPath('data.amount_bdt', 297)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('referral_rewards', 0);
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