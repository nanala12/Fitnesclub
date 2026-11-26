<?php

use Illuminate\Support\Facades\Http;

// Fixture helpers belong only to the explicitly started disposable PHP router.
// Even a normal testing application must not register these authentication/payment shortcuts.
test('browser fixture shortcuts are unavailable through ordinary application routing', function (string $method, string $path) {
    Http::preventStrayRequests();
    $this->call($method, $path)->assertNotFound();
    Http::assertNothingSent();
})->with([
    ['GET', '/__checkout_test/state'], ['POST', '/__checkout_test/login-admin'],
    ['POST', '/__checkout_test/login-staff'], ['POST', '/__checkout_test/confirm'],
    ['POST', '/__checkout_test/confirm-latest'], ['POST', '/__checkout_test/deliver'],
]);
