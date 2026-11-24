<?php

use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Models\Payment;
use App\Services\Payments\Przelewy24Notification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'activefit_testing') {
    throw new RuntimeException('An isolated MySQL testing database is required.');
}
[$script, $paymentId, $barrier, $worker, $operation] = $argv;
config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => '123', 'pos_id' => '456',
    'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret', 'return_url' => 'https://merchant.example.test/return', 'status_url' => 'https://merchant.example.test/status']]);
Http::preventStrayRequests();
Http::fake(function ($request) use ($barrier, $worker, $paymentId, $operation) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Verification cannot hold a database transaction.');
    }
    touch($barrier.'.sent.'.$worker);
    $deadline = microtime(true) + 12;
    while (! file_exists($barrier.'.release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test HTTP release timed out.');
        }
        usleep(10000);
    }
    if ($request->method() === 'GET') {
        $payment = Payment::findOrFail((int) $paymentId);

        return Http::response(['data' => ['sessionId' => $payment->provider_session_id, 'amount' => $payment->amount_grosz,
            'currency' => 'PLN', 'orderId' => 987654, 'status' => 2], 'responseCode' => 0]);
    }

    return $operation === 'verified-remotely' ? Http::response([], 400) : Http::response(['data' => ['status' => 'success'], 'responseCode' => 0]);
});
$payment = Payment::findOrFail((int) $paymentId);
$data = ['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id,
    'amount' => $payment->amount_grosz, 'originAmount' => $payment->amount_grosz, 'currency' => 'PLN',
    'orderId' => 987654, 'methodId' => 25, 'statement' => 'race'];
$data['sign'] = hash('sha384', json_encode([...$data, 'crc' => 'fake-crc-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
touch($barrier.'.ready.'.$worker);
$deadline = microtime(true) + 12;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test start barrier timed out.');
    }
    usleep(10000);
}
$action = app(VerifyPrzelewy24Payment::class);
$membership = $operation === 'reconcile' ? $action->reconcile((int) $paymentId) : $action->handle(Przelewy24Notification::fromArray($data));
echo 'issued:'.$membership?->id;
