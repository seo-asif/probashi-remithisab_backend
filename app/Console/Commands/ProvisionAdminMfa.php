<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Admin\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionAdminMfa extends Command
{
    protected $signature = 'admin:mfa-provision {email : Existing platform administrator email}';

    protected $description = 'Provision first-time administrator TOTP on the server (never through public login)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->hasPassword() || $user->status !== AccountStatus::Active
            || $user->email_verified_at === null) {
            $this->error('No eligible administrator account found.');
            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->where('user_id', $user->id)
            ->where('status', AccountStatus::Active->value)->first();
        if (! $admin) {
            $this->error('No eligible administrator account found.');
            return self::FAILURE;
        }
        if (AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists()) {
            $this->error('MFA is already provisioned. This command cannot reset existing credentials.');
            return self::FAILURE;
        }
        if (! $this->confirm('Provision a new authenticator for this administrator? The setup key will appear once in this terminal.', false)) {
            $this->warn('Cancelled.');
            return self::FAILURE;
        }

        $secret = Totp::secret();
        $created = DB::transaction(function () use ($admin, $secret): bool {
            $locked = PlatformAdmin::query()->whereKey($admin->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== AccountStatus::Active) {
                return false;
            }
            if (AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists()) {
                return false;
            }
            AdminMfaCredential::query()->create([
                'platform_admin_id' => $admin->id,
                'secret' => $secret,
                'last_used_step' => null,
            ]);
            return true;
        });
        if (! $created) {
            $this->error('Provisioning refused. Check administrator status and existing credentials.');
            return self::FAILURE;
        }

        $this->warn('Keep this setup key private. It will not be shown again.');
        $this->line('Account: '.$user->email);
        $this->line('Authenticator setup key: '.$secret);
        $this->line('Type: Time-based (TOTP); digits: 6; period: 30 seconds; algorithm: SHA1.');
        $this->info('Provisioned. The administrator can now log in and enter the authenticator code.');
        return self::SUCCESS;
    }
}