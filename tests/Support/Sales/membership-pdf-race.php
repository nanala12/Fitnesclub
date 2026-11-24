<?php

use App\Actions\Documents\GenerateMembershipPdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $deliveryId, $barrier, $worker] = $argv;
config(['filesystems.disks.membership_documents.root' => $barrier.'.documents']);
Http::preventStrayRequests();
touch($barrier.'.ready.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test start barrier timed out.');
    }
    usleep(10000);
}
echo app(GenerateMembershipPdf::class)->handle((int) $deliveryId);
