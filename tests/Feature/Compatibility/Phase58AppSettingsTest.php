<?php

namespace Tests\Feature\Compatibility;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AuthHelpers;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58AppSettingsTest extends TestCase
{
    use AuthHelpers, Phase3Fixtures, RefreshDatabase;

    public function test_new_account_starts_with_unanswered_onboarding_and_disabled_optional_features(): void
    {
        $user = $this->actAs($this->makeBusiness(['default_currency' => 'NZD']));
        $this->getJson('/api/v1/account/settings')->assertOk()
            ->assertJsonPath('data.language', null)
            ->assertJsonPath('data.mode', null)
            ->assertJsonPath('data.default_currency', 'NZD')
            ->assertJsonPath('data.is_student', null)
            ->assertJsonPath('data.intro_seen', false)
            ->assertJsonPath('data.features.work', false)
            ->assertJsonPath('data.features.goals', false)
            ->assertJsonPath('data.features.remind', false)
            ->assertJsonPath('data.features.auto', true)
            ->assertJsonPath('data.reminders.time', '19:00')
            ->assertJsonPath('data.reminders.payType', 'monthly');

        $this->assertDatabaseMissing('user_app_settings', ['user_id' => $user->id]);
    }

    public function test_settings_persist_through_me_and_password_login(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/account/settings', [
            'language' => 'bn',
            'mode' => 'sender',
            'default_currency' => 'KRW',
            'is_student' => true,
            'intro_seen' => true,
            'features' => ['work' => true],
            'reminders' => ['time' => '20:15', 'pay' => true, 'payType' => 'biweekly', 'payLast' => '2026-10-01'],
        ])->assertOk()
            ->assertJsonPath('data.features.work', true)
            ->assertJsonPath('data.features.auto', false)
            ->assertJsonPath('data.reminders.confirm', true)
            ->assertJsonPath('data.reminders.payType', 'biweekly');

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.app_settings.language', 'bn')
            ->assertJsonPath('data.app_settings.mode', 'sender')
            ->assertJsonPath('data.app_settings.default_currency', 'KRW')
            ->assertJsonPath('data.app_settings.is_student', true)
            ->assertJsonPath('data.app_settings.intro_seen', true);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.app_settings.language', 'bn')
            ->assertJsonPath('data.app_settings.features.work', true)
            ->assertJsonPath('data.app_settings.reminders.payType', 'biweekly');
    }

    public function test_partial_nested_updates_keep_unrelated_preferences(): void
    {
        $this->actAs($this->makeBusiness());
        $this->putJson('/api/v1/account/settings', [
            'features' => ['goals' => true, 'auto' => true],
            'reminders' => ['pay' => true, 'time' => '18:30'],
        ])->assertOk();
        $this->patchJson('/api/v1/account/settings', [
            'features' => ['work' => true],
            'reminders' => ['loan' => true],
        ])->assertOk()
            ->assertJsonPath('data.features.goals', true)
            ->assertJsonPath('data.features.work', true)
            ->assertJsonPath('data.features.auto', false)
            ->assertJsonPath('data.reminders.time', '18:30')
            ->assertJsonPath('data.reminders.pay', true)
            ->assertJsonPath('data.reminders.loan', true);
    }

    public function test_settings_are_private_and_cannot_be_updated_by_another_user(): void
    {
        $business = $this->makeBusiness();
        $first = $this->actAs($business);
        $this->patchJson('/api/v1/account/settings', ['language' => 'bn', 'mode' => 'manager'])->assertOk();
        $second = $this->actAs($business);
        $this->getJson('/api/v1/account/settings')->assertOk()
            ->assertJsonPath('data.language', null)
            ->assertJsonPath('data.mode', null);
        $this->patchJson('/api/v1/account/settings', ['language' => 'en'])->assertOk();
        $this->login($first);
        $this->getJson('/api/v1/account/settings')->assertJsonPath('data.language', 'bn');
        $this->assertDatabaseCount('user_app_settings', 2);
    }

    public function test_invalid_currency_unknown_fields_and_device_pin_are_rejected(): void
    {
        $this->actAs($this->makeBusiness());
        foreach ([
            ['default_currency' => 'BDT'],
            ['language' => 'fr'],
            ['mode' => 'admin'],
            ['reminders' => ['payType' => 'yearly']],
            ['reminders' => ['time' => '25:00']],
            ['features' => ['unknown' => true]],
            ['pin' => '1234'],
            ['app_lock' => true],
        ] as $input) {
            $this->patchJson('/api/v1/account/settings', $input)
                ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        }
        $this->assertDatabaseCount('user_app_settings', 0);
    }

    public function test_settings_endpoints_require_login(): void
    {
        $this->getJson('/api/v1/account/settings')->assertUnauthorized();
        $this->patchJson('/api/v1/account/settings', ['language' => 'bn'])->assertUnauthorized();
    }
}
