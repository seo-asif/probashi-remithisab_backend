<?php

declare(strict_types=1);

/**
 * Opt-in, real MySQL concurrency test for Sender backup restore.
 *
 * This is deliberately outside tests/Feature: Laravel's RefreshDatabase wraps
 * fixtures in an uncommitted transaction that independent worker processes
 * cannot see. Run this ONLY against the disposable MySQL test database.
 *
 * Example (Git Bash):
 * APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3309 \
 * DB_DATABASE=probashi_remithisab_test DB_USERNAME=root \
 * RUN_BACKUP_CONCURRENCY_TEST=1 php tests/Manual/sender_backup_concurrency.php
 *
 * The script creates throwaway fixtures in the test database. The next normal
 * migrate:fresh / PHPUnit test database refresh clears them.
 */

use App\Enums\BusinessRole;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Models\UserMonthlyUsage;
use App\Services\Account\SenderBackupRestoreService;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function requireTestDatabase(): void
{
    $connection = DB::connection();
    $database = (string) ($connection->selectOne('SELECT DATABASE() AS name')->name ?? '');

    if (
        getenv('RUN_BACKUP_CONCURRENCY_TEST') !== '1'
        || !app()->environment('testing')
        || $connection->getDriverName() !== 'mysql'
        || $database !== 'probashi_remithisab_test'
    ) {
        throw new RuntimeException(
            'Refusing to run: requires RUN_BACKUP_CONCURRENCY_TEST=1, ' .
            'APP_ENV=testing and MySQL database probashi_remithisab_test.'
        );
    }
}

function sampleRemittance(string $uuid, ?string $recipientUuid = null): array
{
    return [
        'uuid' => $uuid,
        'recipient_type' => $recipientUuid === null ? 'self' : 'family',
        'recipient_uuid' => $recipientUuid,
        'recipient_name' => $recipientUuid === null ? 'Myself' : 'Mother',
        'goal_uuid' => null,
        'currency' => 'FJD',
        'amount' => '500.00',
        'rate' => '50.0000',
        'charge' => '10.00',
        'charge_type' => 'excluded',
        'incentive_pct' => '0.00',
        'method' => 'Bank',
        'principal' => '500.00',
        'incentive_bdt' => '0.00',
        'family_bdt' => '25000.00',
        'total_cost' => '510.00',
        'received' => true,
        'received_at' => '2026-10-20 12:00:00',
        'occurred_at' => '2026-10-20 10:00:00',
        'timezone' => 'Pacific/Fiji',
    ];
}

function backup(array $recipients, array $remittances): array
{
    return [
        'format' => 'probashi-remithisab-sender',
        'schema_version' => 1,
        'exported_at' => '2026-10-25T10:00:00+12:00',
        'datasets' => [
            'recipients' => $recipients,
            'goals' => [],
            'remittances' => $remittances,
        ],
    ];
}

function assertCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function worker(string $directory, int $index): void
{
    $cases = json_decode(
        (string) file_get_contents($directory . '/cases.json'),
        true,
        128,
        JSON_THROW_ON_ERROR
    );
    $scenario = $cases['cases'][$index];

    file_put_contents($directory . '/ready-' . $index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($directory . '/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent workers did not receive start signal.');
        }
        usleep(20000);
    }

    $business = Business::query()->findOrFail((int) $cases['business_id']);
    try {
        $result = app(CurrentBusiness::class)->runAsUser(
            $business,
            (int) $cases['user_id'],
            static fn () => app(SenderBackupRestoreService::class)->restore($scenario)
        );
        echo json_encode(['success' => true] + $result, JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (ApiException $e) {
        // Quota exhaustion is the expected losing result in scenario two.
        echo json_encode([
            'success' => false,
            'status' => $e->status,
            'code' => $e->errorCode,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}

/** @return list<array<string,mixed>> */
function runRace(
    string $label,
    int $businessId,
    int $userId,
    array $payloads,
    array $environment,
    string $workingDirectory,
): array {
    $directory = sys_get_temp_dir() . '/prh_backup_race_' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Could not create race test directory.');
    }
    $processes = [];
    try {
        file_put_contents($directory . '/cases.json', json_encode([
            'business_id' => $businessId,
            'user_id' => $userId,
            'cases' => $payloads,
        ], JSON_THROW_ON_ERROR));

        foreach (array_keys($payloads) as $index) {
            $process = new Process(
                [PHP_BINARY, __FILE__, 'worker', $directory, (string) $index],
                $workingDirectory,
                $environment
            );
            $process->setTimeout(35);
            $process->start();
            $processes[$index] = $process;
        }

        $deadline = microtime(true) + 20;
        do {
            $ready = true;
            foreach (array_keys($payloads) as $index) {
                if (!is_file($directory . '/ready-' . $index)) {
                    $ready = false;
                    if (!$processes[$index]->isRunning()) {
                        throw new RuntimeException(
                            "$label: worker $index exited early: " . $processes[$index]->getErrorOutput()
                        );
                    }
                }
            }
            if ($ready) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException("$label: timeout waiting for both workers.");
            }
            usleep(20000);
        } while (true);

        touch($directory . '/go');

        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            if (!$process->isSuccessful()) {
                throw new RuntimeException(
                    "$label: worker $index failed: " . $process->getErrorOutput()
                );
            }
            $results[] = json_decode(trim($process->getOutput()), true, 128, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(2);
            }
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

try {
    requireTestDatabase();

    if (($argv[1] ?? '') === 'worker') {
        worker((string) ($argv[2] ?? ''), (int) ($argv[3] ?? -1));
        exit(0);
    }

    $business = Business::factory()->create();
    $user = User::factory()->create();
    BusinessMembership::create([
        'business_id' => $business->id,
        'user_id' => $user->id,
        'role' => BusinessRole::Owner,
    ]);

    $database = (string) DB::connection()->getDatabaseName();
    $connection = config('database.connections.mysql');
    $childEnvironment = [
        'RUN_BACKUP_CONCURRENCY_TEST' => '1',
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $database,
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

    // Scenario 1: two clients replay the same backup simultaneously.
    $recipientUuid = (string) Str::uuid();
    $remittanceUuid = (string) Str::uuid();
    $same = backup(
        [['uuid' => $recipientUuid, 'name' => 'Mother', 'phone' => null]],
        [sampleRemittance($remittanceUuid, $recipientUuid)]
    );
    $result = runRace(
        'duplicate replay',
        $business->id,
        $user->id,
        [$same, $same],
        $childEnvironment,
        $root
    );

    $created = array_map(static fn (array $row): int => (int) ($row['created_total'] ?? -1), $result);
    sort($created);
    assertCheck($created === [0, 2], 'Duplicate replay must create once and skip once.');
    assertCheck(DB::table('sender_recipients')->where('business_id', $business->id)->count() === 1, 'Duplicate family created.');
    assertCheck(DB::table('sender_remittances')->where('business_id', $business->id)->count() === 1, 'Duplicate remittance created.');

    $period = BusinessClock::for($business)->toLocal(now())->format('Y-m-01');
    $usage = UserMonthlyUsage::query()->where('business_id', $business->id)
        ->where('user_id', $user->id)->where('period_start', $period)->firstOrFail();
    assertCheck((int) $usage->sender_remittances_used === 1, 'Duplicate replay charged quota twice.');
    echo "PASS: simultaneous duplicate restore created one family and one remittance, consuming one quota slot.\n";

    // Scenario 2: two DIFFERENT records race for the last Free monthly slot.
    $usage->update(['sender_remittances_used' => 19]);
    $first = backup([], [sampleRemittance((string) Str::uuid())]);
    $second = backup([], [sampleRemittance((string) Str::uuid())]);
    $result = runRace(
        'last free slot',
        $business->id,
        $user->id,
        [$first, $second],
        $childEnvironment,
        $root
    );

    $successes = array_values(array_filter($result, static fn (array $row): bool => $row['success'] === true));
    $rejected = array_values(array_filter($result, static fn (array $row): bool => $row['success'] === false));
    assertCheck(count($successes) === 1 && (int) $successes[0]['created_total'] === 1, 'Expected one successful last-slot restore.');
    assertCheck(
        count($rejected) === 1
            && $rejected[0]['status'] === 409
            && $rejected[0]['code'] === 'PLAN_SENDER_MONTHLY_LIMIT',
        'Expected one quota rejection.'
    );
    assertCheck(
        DB::table('sender_remittances')->where('business_id', $business->id)->count() === 2,
        'Last-slot race inserted the wrong number of remittances.'
    );
    $usage->refresh();
    assertCheck((int) $usage->sender_remittances_used === 20, 'Last-slot race exceeded Free quota.');
    echo "PASS: two concurrent distinct restores consumed only the final Free slot.\n";
    echo "PASS: real MySQL concurrency check complete in disposable test database.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
