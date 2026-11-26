<?php

use App\Actions\Documents\DeliverMembershipDocument;
use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Przelewy24Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../../public'.$path)) {
    return false;
}
$app = require __DIR__.'/browser-app.php';
// These fixture-only endpoints are never loaded by the application routes.
Route::post('__checkout_test/login-admin', function (Request $request) {
    Auth::login(User::where('role', 'admin')->firstOrFail());
    $request->session()->regenerate();

    return response('OK');
})->middleware('web');
Route::post('__checkout_test/login-customer', function (Request $request) {
    $customer = User::where('email', 'customer@example.test')->first();
    if ($customer === null) {
        $customer = User::factory()->create(['first_name' => 'Alicja', 'last_name' => 'Testowa', 'email' => 'customer@example.test']);
        $actor = User::where('role', 'admin')->firstOrFail();
        $plan = MembershipPlan::where('name', 'Karnet miesięczny')->firstOrFail();
        $today = now('Europe/Warsaw')->startOfDay();
        foreach (['active' => [$today->subDays(2), $today->addDays(27)], 'scheduled' => [$today->addDays(28), $today->addDays(57)], 'expired' => [$today->subDays(60), $today->subDays(31)]] as $state => [$start, $end]) {
            $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'purchaser_user_id' => $customer->id,
                'holder_user_id' => $customer->id, 'created_by_user_id' => $actor->id, 'payment_method' => $state === 'expired' ? 'card' : 'cash',
                'purchaser_email' => $customer->email, 'holder_email' => $customer->email, 'recipient_email' => $customer->email]);
            Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => $order->payment_method,
                'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id]);
            $attributes = ['starts_on' => $start->toDateString(), 'expires_on' => $end->toDateString()];
            if ($state === 'scheduled') {
                $source = Membership::where('holder_user_id', $customer->id)->firstOrFail();
                $attributes += ['renewed_from_id' => $source->id, 'renewal_root_membership_id' => $source->id];
            } else {
                $attributes += ['activated_at' => now()->subDays(61), 'activated_by_user_id' => $actor->id];
            }
            Membership::factory()->create(['order_id' => $order->id, ...$attributes]);
        }
    }
    Auth::login($customer);
    $request->session()->regenerate();

    return response('OK');
})->middleware('web');
Route::post('__checkout_test/login-staff', function (Request $request) {
    $staff = User::where('email', 'staff@example.test')->first() ?? User::factory()->employee()->create(['email' => 'staff@example.test', 'first_name' => 'Pracownik', 'last_name' => 'Testowy']);
    Auth::login($staff);
    $request->session()->regenerate();

    return response('OK');
})->middleware('web');
Route::post('__checkout_test/deliver', function () {
    $delivery = DocumentDelivery::latest('id')->firstOrFail();
    app(DeliverMembershipDocument::class)->handle($delivery->id, $delivery->attempts_count);

    return response('OK');
});
Route::get('__checkout_test/state', fn () => response()->json([
    'orders' => Order::count(), 'payments' => Payment::count(), 'memberships' => Membership::count(), 'deliveries' => DocumentDelivery::count(),
    'entries' => MembershipEntry::count(),
    'latestMembership' => Membership::latest('id')->first() === null ? null : ['startsOn' => Membership::latest('id')->first()->starts_on, 'balance' => Membership::latest('id')->first()->entries_remaining],
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
