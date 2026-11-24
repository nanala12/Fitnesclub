<?php

use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(fn () => DB::table('payments')->delete());

test('concurrent notifications or reconciliation finalize one payment pass and PDF request', function (string $otherOperation) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrent confirmation requires MySQL.');
    }
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $order = Order::factory()->create(['accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now()]);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456, 'registration_started_at' => now()]);
    $barrier = tempnam(sys_get_temp_dir(), 'p24-verification-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach (['notify', $otherOperation] as $worker => $operation) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/p24-verification-race.php'), (string) $payment->id, $barrier, (string) $worker, $operation], base_path(), $environment, timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        while ((! file_exists($barrier.'.ready.0') || ! file_exists($barrier.'.ready.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.ready.0') && file_exists($barrier.'.ready.1'))->toBeTrue();
        touch($barrier);
        $deadline = microtime(true) + 10;
        while ((! file_exists($barrier.'.sent.0') || ! file_exists($barrier.'.sent.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.sent.0') && file_exists($barrier.'.sent.1'))->toBeTrue();
        touch($barrier.'.release');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        expect($results[0])->toBe('issued:'.Membership::sole()->id)->and($results[1])->toBe($results[0])
            ->and(Payment::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
            ->and($payment->fresh()->status->value)->toBe('confirmed')->and($order->fresh()->status->value)->toBe('paid')
            ->and(Membership::sole()->activated_at)->toBeNull()->and(Membership::sole()->starts_on)->toBeNull()
            ->and(AuditLog::where('action', 'payment.verified')->count())->toBe(1)
            ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(1);
    } finally {
        touch($barrier.'.release');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($barrier.'*') ?: [] as $file) {
            unlink($file);
        }
    }
})->with(['notify', 'reconcile', 'verified-remotely']);
