<?php

use App\Actions\Payments\InitiatePrzelewy24Payment;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(fn () => DB::table('payments')->delete());

test('two workers register one durable session without holding a transaction during HTTP', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrent session reservation requires MySQL.');
    }
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $order = Order::factory()->create(['accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now()]);
    $barrier = tempnam(sys_get_temp_dir(), 'p24-registration-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/p24-registration-race.php'), (string) $order->id, $barrier, (string) $worker], base_path(), $environment, timeout: 25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.ready.0') || ! file_exists($barrier.'.ready.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.ready.0') && file_exists($barrier.'.ready.1'))->toBeTrue();
        touch($barrier);
        $deadline = microtime(true) + 8;
        while ((! (file_exists($barrier.'.sent.0') || file_exists($barrier.'.sent.1')) || ! (file_exists($barrier.'.done.0') || file_exists($barrier.'.done.1'))) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect((int) file_exists($barrier.'.sent.0') + (int) file_exists($barrier.'.sent.1'))->toBe(1)
            ->and(file_exists($barrier.'.done.0') || file_exists($barrier.'.done.1'))->toBeTrue();
        touch($barrier.'.release');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        expect(count(array_filter($results, fn (string $result): bool => str_starts_with($result, 'ready:'))))->toBe(1)
            ->and(count(array_filter($results, fn (string $result): bool => $result === 'blocked')))->toBe(1)
            ->and(Payment::count())->toBe(1)->and(Payment::sole()->provider_token)->toBe('race-token')
            ->and(Membership::count())->toBe(0)->and($order->fresh()->paid_at)->toBeNull()
            ->and(AuditLog::where('action', 'payment.registration_started')->count())->toBe(1);
        config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => '123', 'pos_id' => '456',
            'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret', 'return_url' => 'https://merchant.example.test/return', 'status_url' => 'https://merchant.example.test/status']]);
        Http::preventStrayRequests();
        Http::fake();
        expect(app(InitiatePrzelewy24Payment::class)->handle($order->id, null)->id)->toBe(Payment::sole()->id);
        Http::assertNothingSent();
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
});
