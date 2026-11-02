<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\AdminMfaCredential;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class IssueAdminMfaRecoveryCodes extends Command
{
    protected $signature = 'admin:mfa-recovery-issue {email : Existing enrolled administrator}';
    protected $description = 'Issue one-time admin MFA recovery codes from the server console';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();
        $admin = $user ? PlatformAdmin::query()->where('user_id', $user->id)->first() : null;
        if (! $user || ! $user->hasPassword() || $user->status !== AccountStatus::Active
            || $user->email_verified_at === null || ! $admin || $admin->status !== AccountStatus::Active
            || ! AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists()) {
            $this->error('No eligible, MFA-enrolled administrator found.');
            return self::FAILURE;
        }
        if (DB::table('platform_admin_mfa_recovery_codes')->where('platform_admin_id', $admin->id)->exists()) {
            $this->error('Existing recovery codes found. Refusing to replace them.');
            return self::FAILURE;
        }
        if (! $this->confirm('Generate recovery codes? They will be displayed only once. Continue?', false)) {
            $this->warn('Cancelled.');
            return self::FAILURE;
        }

        $codes = array_map(static fn (): string => bin2hex(random_bytes(16)), range(1, 8));
        $created = DB::transaction(function () use ($admin, $codes): bool {
            $user = User::query()->whereKey($admin->user_id)->lockForUpdate()->first();
            $locked = PlatformAdmin::query()->whereKey($admin->id)->lockForUpdate()->first();
            if (! $user || ! $user->hasPassword() || $user->status !== AccountStatus::Active
                || $user->email_verified_at === null || ! $locked || $locked->status !== AccountStatus::Active
                || ! AdminMfaCredential::query()->where('platform_admin_id', $admin->id)->exists()
                || DB::table('platform_admin_mfa_recovery_codes')->where('platform_admin_id', $admin->id)->exists()) {
                return false;
            }
            foreach ($codes as $code) {
                DB::table('platform_admin_mfa_recovery_codes')->insert([
                    'platform_admin_id' => $admin->id,
                    'code_hash' => hash('sha256', $code),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return true;
        });
        if (! $created) {
            $this->error('Codes could not be issued. Check status or existing codes.');
            return self::FAILURE;
        }
        $this->warn('Store these codes offline. Each works once, after your password. They are NOT stored in plaintext.');
        foreach ($codes as $code) {
            $this->line($code);
        }
        return self::SUCCESS;
    }
}
