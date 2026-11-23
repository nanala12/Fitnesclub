<?php

use App\Actions\Memberships\IssuePaidMembership;
use App\Models\Membership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $orderId, $barrier, $worker] = $argv;
$id = DB::transaction(function () use ($orderId, $barrier, $worker): int {
    // Both workers establish an old consistent snapshot before racing to acquire current row locks.
    Membership::query()->where('order_id', (int) $orderId)->get();
    touch($barrier.'.'.$worker);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }

    return app(IssuePaidMembership::class)->handle((int) $orderId, null)->id;
});
echo $id;
