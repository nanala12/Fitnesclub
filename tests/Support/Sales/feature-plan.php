<?php

use App\Actions\Memberships\ManageMembershipPlan;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $actorId, $planId, $barrier, $worker] = $argv;
$mode = $argv[5] ?? 'featured';
$actor = User::findOrFail((int) $actorId);
touch($barrier.'.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    }
    usleep(10000);
}
try {
    app(ManageMembershipPlan::class)->save($actor, $planId === '0' ? null : (int) $planId, [
        'name' => 'Concurrent plan '.$worker, 'description' => null, 'kind' => 'time', 'price' => $mode === 'price' ? (string) (151 + (int) $worker) : '150', 'duration_months' => 1, 'included_entries' => null,
        'is_active' => true, 'is_public' => true, 'is_featured' => $mode !== 'price', 'sort_order' => 0, 'revision' => 0,
    ]);
    echo 'success';
} catch (ValidationException) {
    echo 'conflict';
}
