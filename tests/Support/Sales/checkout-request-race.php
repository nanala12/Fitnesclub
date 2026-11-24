<?php

use App\Actions\Orders\CreateOnlineOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $requestId, $barrier, $worker] = $argv;
Http::preventStrayRequests();
$input = json_decode(file_get_contents($barrier.'.input.json'), true, flags: JSON_THROW_ON_ERROR);
touch($barrier.'.ready.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test start barrier timed out.');
    }
    usleep(10000);
}
echo app(CreateOnlineOrder::class)->handle(null, $input, $requestId)->id;
