<?php

use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Przelewy24Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../../public'.$path)) {
    return false;
}
$app = require __DIR__.'/browser-app.php';
// These fixture-only endpoints are never loaded by the application routes.
Route::get('__checkout_test/state', fn () => response()->json([
    'orders' => Order::count(), 'payments' => Payment::count(), 'memberships' => Membership::count(), 'deliveries' => DocumentDelivery::count(),
    'lastReference' => Order::latest('id')->first()?->public_reference,
    'order' => Order::first() === null ? null : ['buyer' => Order::first()->purchaser_email, 'holder' => Order::first()->holder_email,
        'recipient' => Order::first()->recipient_email, 'account' => Order::first()->purchaser_user_id],
    'membership' => Membership::first() === null ? null : ['startsOn' => Membership::first()->starts_on, 'balance' => Membership::first()->entries_remaining],
]));
Route::post('__checkout_test/confirm', function () {
    $payment = Payment::firstOrFail();
    $data = ['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id,
        'amount' => $payment->amount_grosz, 'originAmount' => $payment->amount_grosz, 'currency' => 'PLN',
        'orderId' => 987654, 'methodId' => 25, 'statement' => 'browser fixture'];
    $data['sign'] = hash('sha384', json_encode([...$data, 'crc' => 'fake-crc-secret']));
    app(VerifyPrzelewy24Payment::class)->handle(Przelewy24Notification::fromArray($data));

    return response('OK');
});
$app->handleRequest(Request::capture());
