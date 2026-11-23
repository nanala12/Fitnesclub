<?php

use App\Actions\Memberships\RedeemMembershipEntry;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $membershipId, $actorId, $requestId, $barrier, $worker] = $argv;
try {
    $id = DB::transaction(function () use ($membershipId, $actorId, $requestId, $barrier, $worker): int {
        Membership::query()->whereKey((int) $membershipId)->get();
        touch($barrier.'.'.$worker);
        $deadline = microtime(true) + 10;
        while (! file_exists($barrier)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Test barrier timed out.');
            }
            usleep(10000);
        }

        return app(RedeemMembershipEntry::class)->handle(User::findOrFail((int) $actorId), (int) $membershipId, $requestId)->id;
    }, 3);
    echo 'used:'.$id;
} catch (ValidationException) {
    echo 'blocked';
}
