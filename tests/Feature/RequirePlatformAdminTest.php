<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class RequirePlatformAdminTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Test-only endpoint.
         *
         * This proves the real middleware chain without exposing
         * any production platform-admin route yet.
         */
        Route::middleware([
            'auth:sanctum',
            'platform.admin',
        ])->get('/_test/platform-admin', function () {
            $admin = request()->attributes->get('platform_admin');

            return response()->json([
                'ok' => true,
                'platform_admin_id' => $admin?->id,
            ]);
        });
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->getJson('/_test/platform-admin')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_active_verified_platform_admin_is_allowed(): void
    {
        $user = $this->makeActiveVerifiedUser();

        $admin = PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/platform-admin')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'platform_admin_id' => $admin->id,
            ]);
    }

    public function test_tenant_roles_do_not_grant_platform_admin_access(): void
    {
        $business = $this->makeBusiness();

        foreach ([
            BusinessRole::Owner,
            BusinessRole::Administrator,
            BusinessRole::Operator,
            BusinessRole::ReadOnlyStaff,
        ] as $role) {
            $user = $this->makeMember(
                $business,
                $role,
            );

            $user->forceFill([
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            Sanctum::actingAs($user);

            $this->getJson('/_test/platform-admin')
                ->assertStatus(403)
                ->assertJsonPath(
                    'code',
                    'PLATFORM_ADMIN_FORBIDDEN',
                );
        }
    }

    public function test_platform_admin_row_must_be_active(): void
    {
        $user = $this->makeActiveVerifiedUser();

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Suspended,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/platform-admin')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );
    }

    public function test_underlying_user_account_must_be_active(): void
    {
        $user = $this->makeActiveVerifiedUser();

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        $user->forceFill([
            'status' => AccountStatus::Suspended,
        ])->save();

        Sanctum::actingAs($user);

        $this->getJson('/_test/platform-admin')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );
    }

    public function test_platform_admin_email_must_be_verified(): void
    {
        $user = User::factory()->create();

        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => null,
        ])->save();

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/platform-admin')
            ->assertStatus(403)
            ->assertJsonPath(
                'code',
                'PLATFORM_ADMIN_FORBIDDEN',
            );
    }

    public function test_authorized_platform_admin_is_attached_to_request(): void
    {
        $user = $this->makeActiveVerifiedUser();

        $admin = PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/_test/platform-admin')
            ->assertOk()
            ->assertJsonPath(
                'platform_admin_id',
                $admin->id,
            );
    }

    private function makeActiveVerifiedUser(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        return $user->refresh();
    }
}