<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\DeviceRegistrationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);

if (
    getenv('APP_ENV') !== 'testing' ||
    getenv('DB_DATABASE') !== 'probashi_remithisab_test'
) {
    fwrite(STDERR, "REFUSED: Explicit test environment required.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::selectOne('SELECT DATABASE() AS name')->name;

if (
    !app()->environment('testing') ||
    DB::connection()->getDriverName() !== 'mysql' ||
    $database !== 'probashi_remithisab_test'
) {
    fwrite(STDERR, "REFUSED: Unsafe database connection.\n");
    exit(1);
}

config()->set('registration_devices.hash_key', str_repeat('a', 64));
config()->set('registration_devices.max_registrations', 5);

$service = app(DeviceRegistrationService::class);
$runId = bin2hex(random_bytes(8));
$workerFile = __DIR__ . '/device_registration_worker.php';

$connection = config('database.connections.mysql');

$workerEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => (string) $connection['host'],
    'DB_PORT' => (string) $connection['port'],
    'DB_DATABASE' => 'probashi_remithisab_test',
    'DB_USERNAME' => (string) $connection['username'],
    'DB_PASSWORD' => (string) $connection['password'],
    'DB_URL' => '',
];

function runScenario(
    string $name,
    string $device,
    int $expectedSuccess,
    string $runId,
    string $workerFile,
    array $workerEnv
): void {
    $barrierFile = sys_get_temp_dir()
        . '/probashi-concurrency-' . $runId . '-' . $name;

    $processes = [];

    if (file_put_contents($barrierFile, 'wait') === false) {
        throw new RuntimeException('Cannot create barrier.');
    }

    try {
        for ($i = 1; $i <= 2; $i++) {
            $email = "concurrency-{$runId}-{$name}-{$i}@example.com";

            $processes[] = new Process(
                [PHP_BINARY, $workerFile, $email, $device, $barrierFile],
                dirname(__DIR__, 2),
                $workerEnv,
                null,
                30
            );
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $deadline = microtime(true) + 15;

        while (true) {
            $ready = glob($barrierFile . '.*.ready') ?: [];

            if (count($ready) === 2) {
                break;
            }

            foreach ($processes as $process) {
                if (!$process->isRunning()) {
                    throw new RuntimeException(
                        'Worker exited before synchronization: '
                        . $process->getOutput()
                        . ' '
                        . $process->getErrorOutput()
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    'Workers did not reach the barrier.'
                );
            }

            usleep(10000);
        }

        if (file_put_contents($barrierFile, 'go') === false) {
            throw new RuntimeException('Cannot release workers.');
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            $results[] = [
                'exit_code' => $process->getExitCode(),
                'output' => json_decode(
                    trim($process->getOutput()),
                    true
                ),
                'stderr' => trim($process->getErrorOutput()),
            ];
        }

        echo "\n{$name}:\n";
        echo json_encode(
            $results,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;

        $success = 0;
        $rejected = 0;

        foreach ($results as $result) {
            $status = $result['output']['result'] ?? null;
            $code = $result['output']['code'] ?? null;

            if ($status === 'success' && $result['exit_code'] === 0) {
                $success++;
            } elseif (
                $status === 'rejected' &&
                $code === 'DEVICE_REGISTRATION_LIMIT'
            ) {
                $rejected++;
            } else {
                throw new RuntimeException(
                    "{$name}: Unexpected worker result."
                );
            }
        }

        if ($success !== $expectedSuccess) {
            throw new RuntimeException(
                "{$name}: Expected {$expectedSuccess} successes, got {$success}."
            );
        }

        if ($name === 'final-slot' && $rejected !== 1) {
            throw new RuntimeException(
                'Final-slot must reject exactly one registration.'
            );
        }

        if ($name === 'new-device' && $rejected !== 0) {
            throw new RuntimeException(
                'New-device must accept both registrations.'
            );
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach (glob($barrierFile . '.*.ready') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($barrierFile);
    }
}

$failed = false;

try {
    echo "Database: {$database}\n";
    echo "Run ID: {$runId}\n";

    // Scenario 1: Two workers compete for the fifth slot.
    $finalDevice = substr(hash('sha256', $runId . 'final'), 0, 16);

    for ($i = 1; $i <= 4; $i++) {
        DB::transaction(function () use (
            $runId,
            $i,
            $finalDevice,
            $service
        ): void {
            $user = User::create([
                'name' => 'Concurrency Test',
                'email' => "concurrency-{$runId}-seed-{$i}@example.com",
                'password' => null,
            ]);

            $service->reserve($user, $finalDevice, true);
        });
    }

    runScenario(
        'final-slot',
        $finalDevice,
        1,
        $runId,
        $workerFile,
        $workerEnv
    );

    $finalId = DB::table('registration_devices')
        ->where('device_hash', $service->deviceHash($finalDevice))
        ->value('id');

    $finalCount = DB::table('device_registrations')
        ->where('registration_device_id', $finalId)
        ->count();

    if ($finalCount !== 5) {
        throw new RuntimeException(
            "Final-slot expected 5 registrations, got {$finalCount}."
        );
    }

    echo "Final-slot database count: {$finalCount}\n";

    // Scenario 2: Two workers register a previously unseen device.
    $newDevice = substr(hash('sha256', $runId . 'new'), 0, 16);

    runScenario(
        'new-device',
        $newDevice,
        2,
        $runId,
        $workerFile,
        $workerEnv
    );

    $newId = DB::table('registration_devices')
        ->where('device_hash', $service->deviceHash($newDevice))
        ->value('id');

    $newCount = DB::table('device_registrations')
        ->where('registration_device_id', $newId)
        ->count();

    if ($newCount !== 2) {
        throw new RuntimeException(
            "New-device expected 2 registrations, got {$newCount}."
        );
    }

    echo "New-device database count: {$newCount}\n";
} catch (Throwable $exception) {
    $failed = true;

    fwrite(STDERR, "\nCONCURRENCY TEST FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    try {
        $deviceHashes = [
            $service->deviceHash(
                substr(hash('sha256', $runId . 'final'), 0, 16)
            ),
            $service->deviceHash(
                substr(hash('sha256', $runId . 'new'), 0, 16)
            ),
        ];

        DB::transaction(function () use (
            $runId,
            $deviceHashes
        ): void {
            $deviceIds = DB::table('registration_devices')
                ->whereIn('device_hash', $deviceHashes)
                ->pluck('id');

            DB::table('device_registrations')
                ->whereIn('registration_device_id', $deviceIds)
                ->delete();

            DB::table('users')
                ->where(
                    'email',
                    'like',
                    "concurrency-{$runId}-%@example.com"
                )
                ->delete();

            DB::table('registration_devices')
                ->whereIn('id', $deviceIds)
                ->delete();
        });

        echo "\nConcurrency test records cleaned up.\n";
    } catch (Throwable $exception) {
        $failed = true;

        fwrite(STDERR, "\nCLEANUP FAILED\n");
        fwrite(STDERR, $exception->getMessage() . "\n");
    }
}

if ($failed) {
    exit(1);
}

echo "\nCONCURRENCY TEST PASSED\n";
