<?php

declare (strict_types = 1);

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\DeviceRegistrationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (
    getenv('APP_ENV') !== 'testing' ||
    getenv('DB_DATABASE') !== 'probashi_remithisab_test'
) {
    fwrite(
        STDERR,
        "REFUSED: Explicit testing environment and database are required.\n"
    );
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

try {
    if (app()->environment() !== 'testing') {
        throw new RuntimeException('Worker requires APP_ENV=testing.');
    }

    if (DB::selectOne('SELECT DATABASE() AS name')->name !== 'probashi_remithisab_test') {
        throw new RuntimeException('Unsafe database. Worker stopped.');
    }

    $email = $argv[1] ?? '';
    $androidId = $argv[2] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid test email.');
    }

    config()->set('registration_devices.hash_key', str_repeat('a', 64));
    config()->set('registration_devices.max_registrations', 5);

// Wait until both workers are ready.
    $barrierFile = $argv[3] ?? '';

    if ($barrierFile === '' || !is_file($barrierFile)) {
        throw new RuntimeException('Concurrency barrier is missing.');
    }

    $readyFile = $barrierFile . '.' . getmypid() . '.ready';

    if (file_put_contents($readyFile, 'ready') === false) {
        throw new RuntimeException('Cannot create worker readiness marker.');
    }

    $deadline = microtime(true) + 15;

    while (file_get_contents($barrierFile) !== 'go') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency barrier timed out.');
        }

        usleep(10000);
    }

    DB::transaction(function () use ($email, $androidId): void {
        $user = User::create([
            'name'     => 'Concurrency Test',
            'email'    => $email,
            'password' => null,
        ]);

        app(DeviceRegistrationService::class)->reserve(
            $user,
            $androidId,
            true
        );
    },3);

    echo json_encode(['result' => 'success']) . PHP_EOL;
} catch (ApiException $exception) {
    echo json_encode([
        'result' => 'rejected',
        'code'   => $exception->errorCode,
    ]) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'result'  => 'error',
        'type'    => get_class($exception),
        'message' => $exception->getMessage(),
    ]) . PHP_EOL;

    exit(1);
}
