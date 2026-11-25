<?php

use App\Actions\Memberships\IssuePaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

afterEach(function () {
    DB::table('document_delivery_attempts')->delete();
    foreach (DB::table('document_deliveries')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('document_deliveries')->where('id', $id)->delete();
    }
});

test('two workers or repeated admin requests cannot duplicate the same delivery operation', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Delivery concurrency requires MySQL.');
    }
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $order = Order::factory()->paidAtReception()->create();
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    app(IssuePaidMembership::class)->handle($order->id, null);
    $delivery = DocumentDelivery::sole();
    if ($mode === 'resend') {
        $delivery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    }
    $barrier = tempnam(sys_get_temp_dir(), 'document-delivery-race-');
    unlink($barrier);
    $requestId = (string) Str::uuid();
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/document-delivery-race.php'), (string) $delivery->id, $barrier, (string) $worker, $mode, (string) $admin->id, $requestId], base_path(), $environment, timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        while ((! file_exists($barrier.'.ready.0') || ! file_exists($barrier.'.ready.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.ready.0') && file_exists($barrier.'.ready.1'))->toBeTrue();
        touch($barrier);
        if ($mode === 'send') {
            $deadline = microtime(true) + 10;
            while ((! (file_exists($barrier.'.sending.0') || file_exists($barrier.'.sending.1')) || ! (file_exists($barrier.'.done.0') || file_exists($barrier.'.done.1'))) && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect((int) file_exists($barrier.'.sending.0') + (int) file_exists($barrier.'.sending.1'))->toBe(1);
            touch($barrier.'.release');
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        expect($results[0])->toBe($results[1])->and(Membership::count())->toBe(1)->and(Payment::count())->toBe(1);
        if ($mode === 'send') {
            expect($delivery->fresh()->status->value)->toBe('sent')->and(DocumentDeliveryAttempt::count())->toBe(1)
                ->and(count(glob($barrier.'.sent.*') ?: []))->toBe(1)->and(DocumentDelivery::count())->toBe(1);
        } else {
            expect(DocumentDelivery::count())->toBe(2)->and(DB::table('jobs')->count())->toBe(1)
                ->and(AuditLog::where('action', 'document.resend_requested')->count())->toBe(1);
        }
    } finally {
        touch($barrier.'.release');
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
})->with(['send', 'resend']);
