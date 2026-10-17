<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class MeTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_unauthenticated_request_gets_401_in_the_standard_envelope(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_owner_gets_profile_business_role_and_business_time(): void
    {
        $business = $this->makeBusiness(['name' => 'Test Remit', 'timezone' => 'Pacific/Fiji']);
        Sanctum::actingAs($this->makeMember($business));

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business.name', 'Test Remit')
            ->assertJsonPath('data.business.timezone', 'Pacific/Fiji')
            ->assertJsonPath('data.business.id', $business->uuid)
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'time' => ['server_utc', 'business_local', 'business_timezone']]]);
    }

    public function test_user_without_membership_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'NO_BUSINESS');
    }

    public function test_suspended_membership_is_refused(): void
    {
        $user = $this->makeMember($this->makeBusiness(), BusinessRole::Operator, ['status' => AccountStatus::Suspended]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'NO_BUSINESS');
    }

    public function test_suspended_business_is_refused(): void
    {
        $business = $this->makeBusiness();
        $business->forceFill(['status' => AccountStatus::Suspended])->save();
        Sanctum::actingAs($this->makeMember($business));

        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'BUSINESS_SUSPENDED');
    }

    public function test_suspended_user_is_refused(): void
    {
        $user = $this->makeMember($this->makeBusiness());
        $user->forceFill(['status' => AccountStatus::Suspended])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_user_in_two_businesses_must_choose_and_this_is_not_supported_yet(): void
    {
        $user = $this->makeMember($this->makeBusiness());
        BusinessMembership::create(['business_id' => $this->makeBusiness()->id, 'user_id' => $user->id, 'role' => BusinessRole::Operator]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')->assertStatus(409)->assertJsonPath('code', 'BUSINESS_SELECTION_REQUIRED');
    }

    public function test_a_forged_business_id_in_the_request_is_ignored(): void
    {
        $mine  = $this->makeBusiness(['name' => 'Mine']);
        $other = $this->makeBusiness(['name' => 'Other']);
        Sanctum::actingAs($this->makeMember($mine));

        $this->getJson('/api/v1/me?business_id=' . $other->id, ['X-Business-Id' => (string) $other->uuid])
            ->assertOk()
            ->assertJsonPath('data.business.name', 'Mine');
    }

    public function test_a_business_can_have_only_one_owner(): void
    {
        $business = $this->makeBusiness();
        $this->makeMember($business, BusinessRole::Owner);

        $this->expectException(QueryException::class);
        $this->makeMember($business, BusinessRole::Owner);
    }
}
