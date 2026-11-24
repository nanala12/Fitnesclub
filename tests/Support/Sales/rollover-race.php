<?php

use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Memberships\RenewPaidMembership;
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
[$script, $mode, $orderId, $membershipId, $actorId, $uuid, $barrier, $worker] = $argv;
try {
    $id = DB::transaction(function () use ($mode, $orderId, $membershipId, $actorId, $uuid, $barrier, $worker): int {
        Membership::query()->whereKey((int) $membershipId)->get();
        touch($barrier.'.'.$worker);
        $deadline = microtime(true) + 10;
        while (! file_exists($barrier)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Test barrier timed out.');
            }
            usleep(10000);
        }
        $actor = User::findOrFail((int) $actorId);

        return $mode === 'use'
            ? app(RedeemMembershipEntry::class)->handle($actor, (int) $membershipId, $uuid)->id
            : app(RenewPaidMembership::class)->handle($actor, (int) $orderId, (int) $membershipId)->id;
    }, 3);
    echo $mode.':'.$id;
} catch (ValidationException) {
    echo 'blocked';
}
