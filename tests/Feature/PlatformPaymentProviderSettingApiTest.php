<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\PlatformAdmin;
use App\Models\PlatformPaymentProviderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PlatformPaymentProviderSettingApiTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private const URL =
        '/api/v1/platform/payment-providers/manual-bkash';

    public function test_unauthenticated_user_cannot_access_payment_provider_settings(): void
    {
        $this->getJson(self::URL)
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])
            ->assertStatus(401)
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED',
            );
    }

    public function test_tenant_owner_cannot_manage_platform_payment_settings(): void
    {
        [, $owner] = $this->makeAuthorizedMember(
            BusinessRole::Owner,
        );

        Sanctum::actingAs($owner);

        $this->getJson(self::URL)
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );
    }

    public function test_tenant_administrator_cannot_manage_platform_payment_settings(): void
    {
        [, $administrator] = $this->makeAuthorizedMember(
            BusinessRole::Administrator,
        );

        Sanctum::actingAs($administrator);

        $this->getJson(self::URL)
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );
    }

    public function test_platform_admin_can_read_initial_manual_bkash_settings(): void
    {
        $this->actingAsPlatformAdmin();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.provider',
                'manual_bkash',
            )
            ->assertJsonPath(
                'data.is_enabled',
                false,
            )
            ->assertJsonPath(
                'data.configured',
                false,
            )
            ->assertJsonPath(
                'data.bkash_number',
                null,
            );
    }

    public function test_platform_admin_can_enable_manual_bkash_and_number_is_normalized(): void
    {
        $platformAdmin = $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '+880 1712-345678',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.provider',
                'manual_bkash',
            )
            ->assertJsonPath(
                'data.is_enabled',
                true,
            )
            ->assertJsonPath(
                'data.configured',
                true,
            )
            ->assertJsonPath(
                'data.bkash_number',
                '01712345678',
            );

        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                'manual_bkash',
            )
            ->sole();

        $this->assertTrue(
            $setting->is_enabled,
        );

        $this->assertSame(
            '01712345678',
            $setting->public_account_identifier,
        );

        $this->assertSame(
            $platformAdmin->id,
            $setting->updated_by_platform_admin_id,
        );
    }

    public function test_manual_bkash_accepts_supported_bangladesh_number_formats(): void
    {
        $this->actingAsPlatformAdmin();

        $formats = [
            '8801712345678',
            '1712345678',
            '01712345678',
        ];

        foreach ($formats as $number) {
            $this->putJson(self::URL, [
                'is_enabled'   => true,
                'bkash_number' => $number,
            ])
                ->assertOk()
                ->assertJsonPath(
                    'data.bkash_number',
                    '01712345678',
                );
        }
    }

    public function test_manual_bkash_cannot_be_enabled_without_a_configured_number(): void
    {
        $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'is_enabled' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            );

        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                'manual_bkash',
            )
            ->sole();

        $this->assertFalse(
            $setting->is_enabled,
        );

        $this->assertNull(
            $setting->public_account_identifier,
        );
    }

    public function test_invalid_bkash_number_is_rejected(): void
    {
        $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01234567890',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            );

        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                'manual_bkash',
            )
            ->sole();

        $this->assertFalse(
            $setting->is_enabled,
        );

        $this->assertNull(
            $setting->public_account_identifier,
        );
    }

    public function test_disabling_manual_bkash_preserves_saved_number_when_number_is_omitted(): void
    {
        $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])->assertOk();

        $this->putJson(self::URL, [
            'is_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.is_enabled',
                false,
            )
            ->assertJsonPath(
                'data.configured',
                false,
            )
            ->assertJsonPath(
                'data.bkash_number',
                '01712345678',
            );

        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                'manual_bkash',
            )
            ->sole();

        $this->assertFalse(
            $setting->is_enabled,
        );

        $this->assertSame(
            '01712345678',
            $setting->public_account_identifier,
        );
    }

    public function test_disabled_provider_number_can_be_explicitly_cleared(): void
    {
        $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])->assertOk();

        $this->putJson(self::URL, [
            'is_enabled'   => false,
            'bkash_number' => null,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.is_enabled',
                false,
            )
            ->assertJsonPath(
                'data.configured',
                false,
            )
            ->assertJsonPath(
                'data.bkash_number',
                null,
            );

        $setting =
        PlatformPaymentProviderSetting::query()
            ->where(
                'provider',
                'manual_bkash',
            )
            ->sole();

        $this->assertNull(
            $setting->public_account_identifier,
        );
    }

    public function test_client_cannot_change_the_payment_provider(): void
    {
        $this->actingAsPlatformAdmin();

        $this->putJson(self::URL, [
            'provider'     => 'uddoktapay',
            'is_enabled'   => true,
            'bkash_number' => '01712345678',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.provider',
                'manual_bkash',
            );

        $this->assertDatabaseHas(
            'platform_payment_provider_settings',
            [
                'provider' => 'manual_bkash',
            ],
        );

        $this->assertDatabaseMissing(
            'platform_payment_provider_settings',
            [
                'provider' => 'uddoktapay',
            ],
        );
    }

    public function test_client_cannot_spoof_platform_admin_when_updating_payment_provider(): void
{
    /*
     * Create the Platform Admin whose ID the attacker
     * will try to inject.
     */
    [, $otherAdminUser] = $this->makeAuthorizedMember(
        BusinessRole::ReadOnlyStaff,
    );

    $otherAdmin = $this->makePlatformAdmin(
        $otherAdminUser,
    );

    /*
     * Create and authenticate the real Platform Admin.
     */
    [, $realAdminUser] = $this->makeAuthorizedMember(
        BusinessRole::ReadOnlyStaff,
    );

    $realAdmin = $this->makePlatformAdmin(
        $realAdminUser,
    );

    Sanctum::actingAs($realAdminUser);

    $this->putJson(self::URL, [
        'is_enabled' => true,
        'bkash_number' => '01712345678',

        // Malicious/untrusted identity field.
        'updated_by_platform_admin_id' => $otherAdmin->id,
    ])
        ->assertOk()
        ->assertJsonPath(
            'data.is_enabled',
            true,
        );

    $setting = PlatformPaymentProviderSetting::query()
        ->where(
            'provider',
            'manual_bkash',
        )
        ->sole();

    /*
     * Only the middleware-provided authenticated Platform Admin
     * may become the recorded updater.
     */
    $this->assertSame(
        $realAdmin->id,
        $setting->updated_by_platform_admin_id,
    );

    $this->assertNotSame(
        $otherAdmin->id,
        $setting->updated_by_platform_admin_id,
    );

    $this->assertSame(
        '01712345678',
        $setting->public_account_identifier,
    );

    $this->assertTrue(
        $setting->is_enabled,
    );
}
public function test_platform_admin_payment_provider_update_is_audited(): void
{
    $platformAdmin = $this->actingAsPlatformAdmin();

    $this->putJson(self::URL, [
        'is_enabled'   => true,
        'bkash_number' => '01712345678',
    ])->assertOk();

    $setting = PlatformPaymentProviderSetting::query()
        ->where(
            'provider',
            'manual_bkash',
        )
        ->sole();

    $this->assertDatabaseHas(
        'audit_logs',
        [
            /*
             * This is platform configuration, not tenant-owned data.
             */
            'business_id' => null,

            'actor_user_id' =>
                $platformAdmin->user_id,

            'action' =>
                'platform_payment_provider.updated',

            'target_type' =>
                'PlatformPaymentProviderSetting',

            'target_id' =>
                $setting->id,
        ],
    );
}
public function test_failed_payment_provider_update_is_not_audited_as_success(): void
{
    $platformAdmin = $this->actingAsPlatformAdmin();

    $this->putJson(self::URL, [
        'is_enabled'   => true,
        'bkash_number' => '01234567890',
    ])
        ->assertStatus(422)
        ->assertJsonPath(
            'code',
            'VALIDATION_FAILED',
        );

    $this->assertSame(
        0,
        \App\Models\AuditLog::query()
            ->where(
                'actor_user_id',
                $platformAdmin->user_id,
            )
            ->where(
                'action',
                'platform_payment_provider.updated',
            )
            ->count(),
    );

    $setting = PlatformPaymentProviderSetting::query()
        ->where(
            'provider',
            'manual_bkash',
        )
        ->sole();

    $this->assertFalse(
        $setting->is_enabled,
    );

    $this->assertNull(
        $setting->public_account_identifier,
    );
}
    private function actingAsPlatformAdmin():
    PlatformAdmin {
        [, $adminUser] = $this->makeAuthorizedMember(
            BusinessRole::ReadOnlyStaff,
        );

        $platformAdmin = $this->makePlatformAdmin(
            $adminUser,
        );

        Sanctum::actingAs($adminUser);

        return $platformAdmin;
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
            'status'            => 'active',
            'email_verified_at' => now(),
        ])->save();
    }

    private function makePlatformAdmin(
        $user,
    ): PlatformAdmin {
        return PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'status'  => 'active',
        ]);
    }
}
