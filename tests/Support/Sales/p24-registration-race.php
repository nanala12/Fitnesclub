<?php

use App\Actions\Payments\InitiatePrzelewy24Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $orderId, $barrier, $worker] = $argv;
config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => '123', 'pos_id' => '456',
    'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret', 'return_url' => 'https://merchant.example.test/return', 'status_url' => 'https://merchant.example.test/status']]);
Http::preventStrayRequests();
Http::fake(function () use ($barrier, $worker) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Registration cannot hold a database transaction.');
    }
    touch($barrier.'.sent.'.$worker);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier.'.release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test HTTP release timed out.');
        }
        usleep(10000);
    }

    return Http::response(['data' => ['token' => 'race-token'], 'responseCode' => 0]);
});
touch($barrier.'.ready.'.$worker);
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test start barrier timed out.');
    }
    usleep(10000);
}
try {
    $payment = app(InitiatePrzelewy24Payment::class)->handle((int) $orderId, null);
    echo 'ready:'.$payment->id;
} catch (ValidationException) {
    echo 'blocked';
} finally {
    touch($barrier.'.done.'.$worker);
}
