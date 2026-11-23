<?php

use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

test('concurrent price edits preserve one price revision and its matching audit', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real catalog row locks require MySQL.');
    }
    $plan = MembershipPlan::factory()->create();
    $actors = User::factory()->admin()->count(2)->create();
    $barrier = tempnam(sys_get_temp_dir(), 'plan-price-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/feature-plan.php'), (string) $actors[$worker]->id, (string) $plan->id, $barrier, (string) $worker, 'price'], base_path(), $environment, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        $plan->refresh();
        $audit = AuditLog::where('action', 'plan.updated')->sole();
        $winningWorker = $plan->price_grosz === 15100 ? 0 : 1;
        expect($results)->toBe(['conflict', 'success'])->and($plan->revision)->toBe(1)->and($plan->price_grosz)->toBeIn([15100, 15200])
            ->and($audit->old_values['price_grosz'])->toBe(15000)->and($audit->new_values['price_grosz'])->toBe($plan->price_grosz)
            ->and($audit->actor_user_id)->toBe($actors[$winningWorker]->id);
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
