<?php

use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
afterEach(fn () => DB::table('orders')->update(['checkout_request_id' => null]));

test('two concurrent checkout submissions create one durable purchase', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Checkout submission concurrency requires MySQL.');
    }
    $plan = MembershipPlan::factory()->create(['is_active' => true, 'is_public' => true]);
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $barrier = tempnam(sys_get_temp_dir(), 'checkout-request-race-');
    unlink($barrier);
    file_put_contents($barrier.'.input.json', json_encode(['membership_plan_id' => $plan->id, 'plan_revision' => 0,
        'for_other_person' => false, 'purchaser' => ['first_name' => 'Ania', 'last_name' => 'Testowa', 'email' => 'buyer@example.test', 'phone' => '+48600111222'],
        'accept_regulations' => true, 'accepted_regulation_version_id' => $version->id], JSON_THROW_ON_ERROR));
    $id = (string) Str::uuid();
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/checkout-request-race.php'), $id, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.ready.0') || ! file_exists($barrier.'.ready.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.ready.0') && file_exists($barrier.'.ready.1'))->toBeTrue();
        touch($barrier);
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())->and(trim($process->getOutput()))->toBe((string) Order::sole()->id);
        }
        expect(Order::count())->toBe(1)->and(Payment::count())->toBe(0);
    } finally {
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
