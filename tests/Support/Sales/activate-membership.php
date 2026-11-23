<?php

use App\Actions\Memberships\ActivateMembership;
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
[$script, $membershipId, $actorId, $barrier, $worker] = $argv;
$result = DB::transaction(function () use ($membershipId, $actorId, $barrier, $worker): string {
    Membership::query()->whereKey((int) $membershipId)->get();
    touch($barrier.'.'.$worker);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }
    $membership = app(ActivateMembership::class)->handle(User::findOrFail((int) $actorId), (int) $membershipId);

    return json_encode(['id' => $membership->id, 'actor' => $membership->activated_by_user_id, 'activated' => $membership->activated_at->toISOString()], JSON_THROW_ON_ERROR);
});
echo $result;
