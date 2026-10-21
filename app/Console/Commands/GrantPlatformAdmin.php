<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform-admin:grant
                            {email : Email address of an existing user}';

    protected $description =
        'Grant platform administrator access to an existing verified active user';

    public function handle(): int
    {
        $email = mb_strtolower(
            trim((string) $this->argument('email'))
        );

        $user = User::query()
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $this->error(
                'User not found. No platform administrator was created.'
            );

            return self::FAILURE;
        }

        if ($user->status !== AccountStatus::Active) {
            $this->error(
                'The user account is not active. No changes were made.'
            );

            return self::FAILURE;
        }

        if ($user->email_verified_at === null) {
            $this->error(
                'The user email is not verified. No changes were made.'
            );

            return self::FAILURE;
        }

        $existing = PlatformAdmin::query()
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            if ($existing->status === AccountStatus::Active) {
                $this->info(
                    'This user is already an active platform administrator.'
                );

                return self::SUCCESS;
            }

            /*
             * Never silently undo an administrative suspension.
             * Reactivation should be an explicit operation.
             */
            $this->error(
                'This platform administrator is suspended. No changes were made.'
            );

            return self::FAILURE;
        }

        PlatformAdmin::create([
            'user_id' => $user->id,
            'status' => AccountStatus::Active,
        ]);

        $this->info(
            'Platform administrator access granted successfully.'
        );

        return self::SUCCESS;
    }
}