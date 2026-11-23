<?php

use App\Actions\Memberships\IssuePaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('two employees activate once even with existing repeatable read snapshots', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrent activation row locks require MySQL.');
    }
    $order = Order::factory()->paidAtReception()->create();
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    $membership = app(IssuePaidMembership::class)->handle($order->id, null);
    $actors = User::factory()->employee()->count(2)->create();
    $barrier = tempnam(sys_get_temp_dir(), 'activation-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/activate-membership.php'), (string) $membership->id, (string) $actors[$worker]->id, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.0') && file_exists($barrier.'.1'))->toBeTrue();
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        expect($results[0])->toBe($results[1])->and(Membership::count())->toBe(1)
            ->and(AuditLog::where('action', 'membership.activated')->count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
            ->and($membership->fresh()->entries()->count())->toBe(0)
            ->and(AuditLog::where('action', 'membership.activated')->sole()->actor_user_id)->toBe($membership->fresh()->activated_by_user_id);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.0', $barrier.'.1'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
