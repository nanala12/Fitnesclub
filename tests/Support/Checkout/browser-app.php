<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== '/tmp/activefit-checkout-browser.sqlite') {
    throw new RuntimeException('Dedicated disposable checkout browser database required.');
}
config(['inertia.ssr.enabled' => false, 'session.files' => '/tmp/activefit-checkout-browser-sessions',
    'payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false,
        'merchant_id' => 123, 'pos_id' => 456, 'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/platnosc/powrot', 'status_url' => 'https://merchant.example.test/payments/przelewy24/notification']]);
app(Vite::class)->useHotFile('/tmp/activefit-checkout-unused-hot-file');
Http::preventStrayRequests();
Http::fake(function ($request) {
    if (! str_starts_with($request->url(), 'https://sandbox.przelewy24.pl/api/v1/transaction/')) {
        throw new RuntimeException('Unexpected test transport.');
    }
    if ($request->method() === 'POST') {
        if (($_COOKIE['checkout_test_gateway'] ?? '') === 'timeout') {
            return Http::failedConnection();
        }

        return Http::response(['data' => ['token' => 'browser-test-token'], 'responseCode' => 0]);
    }

    return Http::response(['data' => ['status' => 'success'], 'responseCode' => 0]);
});

return $app;
