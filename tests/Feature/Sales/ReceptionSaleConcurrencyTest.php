<?php

use App\Actions\Orders\CreateReceptionOrder;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(function () {
    if (DB::getDriverName() === 'mysql') {
        // Dispose only this isolated database's sale fixtures before restoring the schema.
        DB::table('orders')->whereNotNull('reception_request_id')->update(['reception_request_id' => null]);
    }
});

test('receipt creation confirmation and complete sale are idempotent under concurrent requests', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Receipt row-lock races require MySQL.');
    }
    $seller = User::factory()->employee()->create();
    $other = User::factory()->employee()->create();
    $plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true]);
    $requestId = (string) Str::uuid();
    $order = $mode === 'confirm' ? app(CreateReceptionOrder::class)->handle($seller, [
        'request_id' => $requestId, 'membership_plan_id' => $plan->id, 'plan_revision' => 0, 'payment_method' => 'cash', 'for_other_person' => false,
        'purchaser' => ['first_name' => 'Ania', 'last_name' => 'Testowa', 'email' => 'buyer@example.test', 'phone' => '+48600111222'],
    ]) : null;
    $barrier = tempnam(sys_get_temp_dir(), 'reception-sale-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $actor = $mode === 'confirm' && $worker === 1 ? $other : $seller;
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/reception-sale-race.php'), $mode, (string) $actor->id, (string) $plan->id, (string) ($order?->id ?? 0), $requestId, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.0') && file_exists($barrier.'.1'))->toBeTrue();
        touch($barrier);
        $ids = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $ids[] = trim($process->getOutput());
        }
        expect($ids[0])->toBe($ids[1])->and(Order::count())->toBe(1)
            ->and(Payment::count())->toBe($mode === 'create' ? 0 : 1)->and(Membership::count())->toBe($mode === 'create' ? 0 : 1)
            ->and(DocumentDelivery::count())->toBe($mode === 'create' ? 0 : 1)
            ->and(AuditLog::where('action', 'reception.order_created')->count())->toBe(1)
            ->and(AuditLog::where('action', 'reception.payment_confirmed')->count())->toBe($mode === 'create' ? 0 : 1);
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
})->with(['create', 'confirm', 'complete']);
