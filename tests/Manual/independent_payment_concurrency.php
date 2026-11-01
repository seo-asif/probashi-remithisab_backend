<?php

declare(strict_types=1);

/**
 * Opt-in real-MySQL concurrency regression for independent plan payments.
 * Run ONLY against disposable probashi_remithisab_test, with migrations already applied.
 * Fixtures remain in that test database until its next refresh.
 */

use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ManagerSubscription;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use App\Models\SenderSubscription;
use App\Models\User;
use App\Support\Plans\PaymentApprovalService;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function ensureSafeDatabase(): void
{
    $db = DB::connection();
    $name = (string) ($db->selectOne('SELECT DATABASE() AS name')->name ?? '');
    if (getenv('RUN_PAYMENT_CONCURRENCY_TEST') !== '1'
        || !app()->environment('testing')
        || $db->getDriverName() !== 'mysql'
        || $name !== 'probashi_remithisab_test') {
        throw new RuntimeException('Refusing to run: requires RUN_PAYMENT_CONCURRENCY_TEST=1, APP_ENV=testing, and MySQL probashi_remithisab_test.');
    }
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function paymentFor(Business $business, User $user, PlanPaymentProduct $product, int $months): PlanPaymentRequest
{
    $trx = strtoupper(bin2hex(random_bytes(5)));
    return app(PaymentRequestService::class)->createIndependent(
        $business, $user, $product, $months, $trx, '01712345678'
    );
}

function auditCount(int $paymentId): int
{
    return AuditLog::query()->where('action', 'plan_payment.approved')
        ->where('target_type', 'PlanPaymentRequest')
        ->where('target_id', $paymentId)->count();
}

function worker(string $directory, int $index): void
{
    $cases = json_decode((string) file_get_contents($directory . '/cases.json'), true, 128, JSON_THROW_ON_ERROR);
    $case = $cases[$index];
    file_put_contents($directory . '/ready-' . $index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($directory . '/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for start signal.');
        }
        usleep(20000);
    }
    try {
        $payment = PlanPaymentRequest::query()->findOrFail((int) $case['payment_id']);
        $admin = PlatformAdmin::query()->findOrFail((int) $case['admin_id']);
        $service = app(PaymentApprovalService::class);
        $result = $case['action'] === 'reject'
            ? $service->reject($payment, $admin, 'Concurrent verification rejection')
            : $service->approve($payment, $admin);
        echo json_encode(['ok' => true, 'status' => $result->status->value], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (ApiException $e) {
        echo json_encode(['ok' => false, 'status' => $e->status, 'code' => $e->errorCode], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
}

/** @return list<array<string,mixed>> */
function race(string $label, array $cases, array $environment, string $root): array
{
    $dir = sys_get_temp_dir() . '/prh_payment_race_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temporary race directory.');
    }
    $processes = [];
    try {
        file_put_contents($dir . '/cases.json', json_encode($cases, JSON_THROW_ON_ERROR));
        foreach (array_keys($cases) as $index) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $dir, (string) $index], $root, $environment);
            $process->setTimeout(40);
            $process->start();
            $processes[$index] = $process;
        }
        $deadline = microtime(true) + 20;
        while (true) {
            $allReady = true;
            foreach ($processes as $index => $process) {
                if (!is_file($dir . '/ready-' . $index)) {
                    $allReady = false;
                    if (!$process->isRunning()) {
                        throw new RuntimeException("$label: worker $index exited early: " . $process->getErrorOutput());
                    }
                }
            }
            if ($allReady) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException("$label: timed out waiting for workers.");
            }
            usleep(20000);
        }
        touch($dir . '/go');
        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            if (!$process->isSuccessful()) {
                throw new RuntimeException("$label: worker $index failed: " . $process->getErrorOutput());
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
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

function newBusinessFor(User $user): Business
{
    $business = Business::factory()->create();
    BusinessMembership::create(['business_id' => $business->id, 'user_id' => $user->id, 'role' => BusinessRole::Owner]);
    return $business;
}

function approved(PlanPaymentRequest $payment): bool
{
    $payment->refresh();
    return $payment->status === PlanPaymentStatus::Approved && $payment->applied_at !== null;
}

try {
    ensureSafeDatabase();
    if (($argv[1] ?? '') === 'worker') {
        worker((string) ($argv[2] ?? ''), (int) ($argv[3] ?? -1));
        exit(0);
    }
    $connection = config('database.connections.mysql');
    $environment = [
        'RUN_PAYMENT_CONCURRENCY_TEST' => '1',
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
        'QUEUE_CONNECTION' => 'sync',
    ];
    $admin = PlatformAdmin::create(['user_id' => User::factory()->create()->id]);

    // Same payment, two approvals: apply entitlement once.
    $sender = User::factory()->create();
    $business = newBusinessFor($sender);
    $same = paymentFor($business, $sender, PlanPaymentProduct::SenderPlus, 1);
    $results = race('duplicate approval', [
        ['payment_id' => $same->id, 'admin_id' => $admin->id, 'action' => 'approve'],
        ['payment_id' => $same->id, 'admin_id' => $admin->id, 'action' => 'approve'],
    ], $environment, $root);
    check(count(array_filter($results, static fn ($r) => $r['ok'] && $r['status'] === 'approved')) === 2, 'Duplicate approval: both retries should return approved.');
    check(approved($same), 'Duplicate approval: payment not approved.');
    check(SenderSubscription::where('user_id', $sender->id)->count() === 1, 'Duplicate approval: duplicate subscription.');
    $firstExpiry = SenderSubscription::where('user_id', $sender->id)->firstOrFail()->access_ends_at;
    check(auditCount($same->id) === 1, 'Duplicate approval: expected one approval audit event.');
    echo "PASS: concurrent duplicate Sender Plus approval applied once.\n";

    // Distinct sender payments across two businesses must serialize on user row.
    $secondBusiness = newBusinessFor($sender);
    $firstRenewal = paymentFor($business, $sender, PlanPaymentProduct::SenderPlus, 1);
    $secondRenewal = paymentFor($secondBusiness, $sender, PlanPaymentProduct::SenderPlus, 1);
    $results = race('cross-business sender renewal', [
        ['payment_id' => $firstRenewal->id, 'admin_id' => $admin->id, 'action' => 'approve'],
        ['payment_id' => $secondRenewal->id, 'admin_id' => $admin->id, 'action' => 'approve'],
    ], $environment, $root);
    check(count(array_filter($results, static fn ($r) => $r['ok'] && $r['status'] === 'approved')) === 2, 'Sender renewal: both payments must be approved.');
    check(approved($firstRenewal) && approved($secondRenewal), 'Sender renewal: payment state mismatch.');
    $senderSubscription = SenderSubscription::where('user_id', $sender->id)->firstOrFail();
    check(SenderSubscription::where('user_id', $sender->id)->count() === 1, 'Sender renewal: duplicate subscription.');
    check($senderSubscription->access_ends_at->equalTo($firstExpiry->addMonthsNoOverflow(2)), 'Sender renewal: expiry did not include both purchased months.');
    check(auditCount($firstRenewal->id) === 1 && auditCount($secondRenewal->id) === 1, 'Sender renewal: approval audit mismatch.');
    echo "PASS: cross-business Sender Plus renewals both applied.\n";

    // Two Manager Pro requests in the same business.
    $manager = User::factory()->create();
    $managerBusiness = newBusinessFor($manager);
    $managerFirst = paymentFor($managerBusiness, $manager, PlanPaymentProduct::ManagerPro, 1);
    $managerSecond = paymentFor($managerBusiness, $manager, PlanPaymentProduct::ManagerPro, 1);
    $results = race('manager renewal', [
        ['payment_id' => $managerFirst->id, 'admin_id' => $admin->id, 'action' => 'approve'],
        ['payment_id' => $managerSecond->id, 'admin_id' => $admin->id, 'action' => 'approve'],
    ], $environment, $root);
    check(count(array_filter($results, static fn ($r) => $r['ok'] && $r['status'] === 'approved')) === 2, 'Manager renewal: both approvals must succeed.');
    check(approved($managerFirst) && approved($managerSecond), 'Manager renewal: payment state mismatch.');
    $managerSubscription = ManagerSubscription::where('business_id', $managerBusiness->id)->firstOrFail();
    check(ManagerSubscription::where('business_id', $managerBusiness->id)->count() === 1, 'Manager renewal: duplicate subscription.');
    check($managerSubscription->access_ends_at->greaterThanOrEqualTo(now('UTC')->addMonthsNoOverflow(2)->subMinutes(1)), 'Manager renewal: expiry too short.');
    check(auditCount($managerFirst->id) === 1 && auditCount($managerSecond->id) === 1, 'Manager renewal: approval audit mismatch.');
    echo "PASS: concurrent Manager Pro renewals both applied.\n";

    // Approve vs reject: one decision wins; losing operation cannot overwrite it.
    $decidingUser = User::factory()->create();
    $decidingBusiness = newBusinessFor($decidingUser);
    $contested = paymentFor($decidingBusiness, $decidingUser, PlanPaymentProduct::SenderPlus, 1);
    $results = race('approve vs reject', [
        ['payment_id' => $contested->id, 'admin_id' => $admin->id, 'action' => 'approve'],
        ['payment_id' => $contested->id, 'admin_id' => $admin->id, 'action' => 'reject'],
    ], $environment, $root);
    $contested->refresh();
    check(in_array($contested->status, [PlanPaymentStatus::Approved, PlanPaymentStatus::Rejected], true), 'Approve/reject: invalid final status.');
    $wonApproval = $contested->status === PlanPaymentStatus::Approved;
    check(($contested->applied_at !== null) === $wonApproval, 'Approve/reject: applied_at mismatch.');
    check(SenderSubscription::where('user_id', $decidingUser->id)->count() === ($wonApproval ? 1 : 0), 'Approve/reject: entitlement mismatch.');
    check(auditCount($contested->id) === ($wonApproval ? 1 : 0), 'Approve/reject: approval audit mismatch.');
    check(count(array_filter($results, static fn ($r) => $r['ok'])) === 1, 'Approve/reject: exactly one operation must succeed.');
    echo "PASS: competing approval/rejection produced one consistent decision.\n";
    echo "PASS: independent payment concurrency checks complete.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
