<?php

use App\Actions\Memberships\RenewPaidMembership;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $orderId, $sourceId, $actorId, $barrier, $worker] = $argv;
$id = DB::transaction(function () use ($orderId, $sourceId, $actorId, $barrier, $worker): int {
    Membership::query()->whereKey((int) $sourceId)->get();
    touch($barrier.'.'.$worker);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }

    return app(RenewPaidMembership::class)->handle(User::findOrFail((int) $actorId), (int) $orderId, (int) $sourceId)->id;
}, 3);
echo $id;
