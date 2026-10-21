<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantPlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_platform_admin_to_active_verified_user(): void
    {
        $user = $this->makeEligibleUser();

        $this->artisan(
            'platform-admin:grant',
            ['email' => $user->email],
        )->assertExitCode(Command::SUCCESS);

        $admin = PlatformAdmin::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            AccountStatus::Active,
            $admin->status,
        );
    }

    public function test_grant_is_idempotent_for_existing_active_admin(): void
    {
        $user = $this->makeEligibleUser();

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        $this->artisan(
            'platform-admin:grant',
            ['email' => $user->email],
        )->assertExitCode(Command::SUCCESS);

        $this->assertSame(
            1,
            PlatformAdmin::query()
                ->where('user_id', $user->id)
                ->count(),
        );
    }

    public function test_unknown_user_is_rejected(): void
    {
        $this->artisan(
            'platform-admin:grant',
            ['email' => 'missing@example.com'],
        )->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount(
            'platform_admins',
            0,
        );
    }

    public function test_suspended_user_is_rejected(): void
    {
        $user = $this->makeEligibleUser();

        $user->forceFill([
            'status' => AccountStatus::Suspended,
        ])->save();

        $this->artisan(
            'platform-admin:grant',
            ['email' => $user->email],
        )->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing(
            'platform_admins',
            [
                'user_id' => $user->id,
            ],
        );
    }

    public function test_unverified_user_is_rejected(): void
    {
        $user = $this->makeEligibleUser();

        $user->forceFill([
            'email_verified_at' => null,
        ])->save();

        $this->artisan(
            'platform-admin:grant',
            ['email' => $user->email],
        )->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing(
            'platform_admins',
            [
                'user_id' => $user->id,
            ],
        );
    }

    public function test_suspended_platform_admin_is_not_silently_reactivated(): void
    {
        $user = $this->makeEligibleUser();

        $admin = PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Suspended,
        ]);

        $this->artisan(
            'platform-admin:grant',
            ['email' => $user->email],
        )->assertExitCode(Command::FAILURE);

        $this->assertSame(
            AccountStatus::Suspended,
            $admin->refresh()->status,
        );

        $this->assertSame(
            1,
            PlatformAdmin::query()
                ->where('user_id', $user->id)
                ->count(),
        );
    }

    public function test_email_lookup_is_trimmed_and_case_insensitive(): void
    {
        $user = $this->makeEligibleUser();

        $this->artisan(
            'platform-admin:grant',
            [
                'email' => '  ' .
                    mb_strtoupper($user->email) .
                    '  ',
            ],
        )->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas(
            'platform_admins',
            [
                'user_id' => $user->id,
                'status' => AccountStatus::Active->value,
            ],
        );
    }

    private function makeEligibleUser(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        return $user->refresh();
    }
}