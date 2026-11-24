<?php

use App\Actions\Memberships\IssuePaidMembership;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('two PDF workers create one committed private artifact without duplicating a pass or delivery', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('PDF generation concurrency requires MySQL.');
    }
    $order = Order::factory()->paidAtReception()->create();
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    app(IssuePaidMembership::class)->handle($order->id, null);
    $delivery = DocumentDelivery::sole();
    $barrier = tempnam(sys_get_temp_dir(), 'membership-pdf-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/membership-pdf-race.php'), (string) $delivery->id, $barrier, (string) $worker], base_path(), $environment, timeout: 25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.ready.0') || ! file_exists($barrier.'.ready.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.ready.0') && file_exists($barrier.'.ready.1'))->toBeTrue();
        touch($barrier);
        $paths = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $paths[] = trim($process->getOutput());
        }
        config(['filesystems.disks.membership_documents.root' => $barrier.'.documents']);
        Storage::forgetDisk('membership_documents');
        expect($paths[0])->toBe($paths[1])->and($delivery->fresh()->storage_path)->toBe($paths[0])
            ->and(Storage::disk('membership_documents')->allFiles())->toBe([$paths[0]])
            ->and(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
            ->and(Storage::disk('membership_documents')->get($paths[0]))->toStartWith('%PDF-');
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        File::deleteDirectory($barrier.'.documents');
        foreach (glob($barrier.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});
