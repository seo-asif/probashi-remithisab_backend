<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Services\Work\WorkService;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Validation\Reject;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);

if (
    getenv('RUN_WORK_DAY_RACE') !== '1' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('DB_DATABASE') !== 'probashi_remithisab_test'
) {
    fwrite(STDERR, "REFUSED: Explicit test environment required.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) (DB::selectOne('SELECT DATABASE() AS name')->name ?? '');

if (
    !app()->environment('testing') ||
    DB::connection()->getDriverName() !== 'mysql' ||
    $database !== 'probashi_remithisab_test'
) {
    throw new RuntimeException('REFUSED: Unsafe database connection.');
}

if (($argv[1] ?? '') === 'worker') {
    $dir = (string) ($argv[2] ?? '');
    $index = (int) ($argv[3] ?? -1);

    $case = json_decode(
        (string) file_get_contents($dir . '/case.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    file_put_contents("$dir/ready-$index", 'ready');

    $deadline = microtime(true) + 20;

    while (!is_file("$dir/go")) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Start signal timeout.');
        }
        usleep(10000);
    }

    $business = Business::findOrFail($case['business_id']);

    try {
        $day = app(CurrentBusiness::class)->runAsUser(
            $business,
            (int) $case['user_id'],
            fn () => app(WorkService::class)->saveDay(
                $case['date'],
                ['kind' => 'work', 'hours' => (string) $case['hours'][$index]],
                createOnly: true
            )
        );

        echo json_encode([
            'status' => 'created',
            'hours' => (string) $day->hours,
        ], JSON_THROW_ON_ERROR);
    } catch (ValidationException $e) {
        $message = $e->errors()['work_date'][0] ?? '';
        [$code] = Reject::split($message);

        echo json_encode([
            'status' => 'rejected',
            'code' => $code,
        ], JSON_THROW_ON_ERROR);
    }

    exit(0);
}

$dir = sys_get_temp_dir() . '/prh_work_race_' . bin2hex(random_bytes(8));

if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create temporary directory.');
}

$processes = [];

try {
    $user = User::factory()->create();
    $business = Business::factory()->create([
        'timezone' => 'Pacific/Fiji',
    ]);

    BusinessMembership::create([
        'business_id' => $business->id,
        'user_id' => $user->id,
        'role' => BusinessRole::Owner,
    ]);

    $date = '2026-10-01';

    file_put_contents($dir . '/case.json', json_encode([
        'business_id' => $business->id,
        'user_id' => $user->id,
        'date' => $date,
        'hours' => ['8', '10'],
    ], JSON_THROW_ON_ERROR));

    $connection = config('database.connections.mysql');

    $environment = [
        'RUN_WORK_DAY_RACE' => '1',
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => 'probashi_remithisab_test',
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ];

    for ($i = 0; $i < 2; $i++) {
        $process = new Process(
            [PHP_BINARY, __FILE__, 'worker', $dir, (string) $i],
            $root,
            $environment
        );

        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }

    $deadline = microtime(true) + 20;

    while (
        !is_file("$dir/ready-0") ||
        !is_file("$dir/ready-1")
    ) {
        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                throw new RuntimeException(
                    'Worker exited early: ' . $process->getErrorOutput()
                );
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('Workers did not become ready.');
        }

        usleep(10000);
    }

    touch("$dir/go");

    $results = [];

    foreach ($processes as $process) {
        $process->wait();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Worker failed: ' . $process->getErrorOutput()
            );
        }

        $results[] = json_decode(
            trim($process->getOutput()),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    $created = array_values(array_filter(
        $results,
        fn ($result) => ($result['status'] ?? '') === 'created'
    ));

    $rejected = array_values(array_filter(
        $results,
        fn ($result) =>
            ($result['status'] ?? '') === 'rejected' &&
            ($result['code'] ?? '') === 'work_date_taken'
    ));

    $records = DB::table('work_days')
        ->where('business_id', $business->id)
        ->where('owner_user_id', $user->id)
        ->where('work_date', $date)
        ->get();

    if (
        count($created) !== 1 ||
        count($rejected) !== 1 ||
        $records->count() !== 1 ||
        bccomp(
            (string) $records->first()->hours,
            (string) $created[0]['hours'],
            2
        ) !== 0
    ) {
        throw new RuntimeException(
            'FAILED: Concurrent creation did not preserve one record. ' .
            json_encode($results)
        );
    }

    echo "PASS: One creation and one work_date_taken rejection.\n";
    echo "PASS: Exactly one work record with unchanged hours.\n";
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(2);
        }
    }

    foreach (glob("$dir/*") ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir);
}
