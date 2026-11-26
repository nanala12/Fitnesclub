<?php

use App\Actions\Payments\ConfirmReceptionPayment;
use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Services\Payments\Przelewy24Notification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00Z'));
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->employee()->create();
});

afterEach(function () {
    DB::table('orders')->update(['renewal_source_membership_id' => null]);
    DB::table('document_deliveries')->delete();
    DB::table('payments')->delete();
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

function dashboardReceipt(int $price = 10000, array $membership = [], ?string $paidAt = null, bool $entries = false): Order
{
    $plan = $entries ? MembershipPlan::factory()->entries(8)->create(['price_grosz' => $price]) : MembershipPlan::factory()->create(['price_grosz' => $price]);
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'status' => 'pending', 'paid_at' => null,
        'recipient_email' => 'holder@example.test', 'ordered_at' => $paidAt ?? now()]);
    if ($paidAt !== null) {
        test()->travelTo(CarbonImmutable::parse($paidAt));
    }
    $member = app(ConfirmReceptionPayment::class)->handle(test()->staff, $order->id);
    test()->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00Z'));
    if ($membership !== []) {
        $member->forceFill(['activated_at' => now()->subDays(20), 'activated_by_user_id' => test()->staff->id, ...$membership])->save();
    }

    return $order->fresh();
}

test('empty dashboard uses Warsaw month range private headers and readable zero totals', function () {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard')->where('dashboard.today', '2026-11-26')
            ->where('dashboard.from', '2026-11-01')->where('dashboard.to', '2026-11-26')
            ->where('dashboard.activeMemberships', 0)->where('dashboard.expiringMemberships', 0)
            ->where('dashboard.salesCount', 0)->where('dashboard.salesLabel', '0,00 zł')->has('dashboard.recentOrders', 0));
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('active and expiring counts exclude first visits future periods exhausted transferred and unpaid passes', function () {
    dashboardReceipt(); // Paid but waiting for first visit.
    dashboardReceipt(10000, ['starts_on' => '2026-11-25', 'expires_on' => '2026-11-26']); // Ends today.
    dashboardReceipt(10000, ['starts_on' => '2026-11-25', 'expires_on' => '2026-12-03']); // Inclusive +7 days.
    dashboardReceipt(10000, ['starts_on' => '2026-11-25', 'expires_on' => '2026-12-04']); // Active, outside window.
    dashboardReceipt(10000, ['starts_on' => '2026-10-25', 'expires_on' => '2026-11-25']); // Expired.
    dashboardReceipt(10000, ['starts_on' => '2026-11-27', 'expires_on' => '2026-12-26']); // Future period.
    dashboardReceipt(10000, ['starts_on' => '2026-11-26', 'entries_remaining' => 0], entries: true); // Exhausted/rolled over.
    dashboardReceipt(10000, ['starts_on' => '2026-11-26'], entries: true); // Non-expiring active.
    $unpaid = Order::factory()->create();
    Membership::factory()->create(['order_id' => $unpaid->id, 'starts_on' => '2026-11-25', 'expires_on' => '2026-12-01', 'activated_at' => now(), 'activated_by_user_id' => $this->staff->id]);
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.activeMemberships', 4)->where('dashboard.expiringMemberships', 2)->where('dashboard.salesCount', 8));
});

test('financial totals count confirmed orders once and reject unresolved mismatched or missing evidence', function () {
    $paid = dashboardReceipt(12345);
    // Failed attempts cannot multiply a successful sale.
    Payment::factory()->create(['order_id' => $paid->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'failed']);
    Order::factory()->create();
    $missing = Order::factory()->paidAtReception()->create();
    $bad = Order::factory()->paidAtReception()->create();
    Payment::factory()->create(['order_id' => $bad->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(),
        'confirmed_by_user_id' => $this->staff->id, 'amount_grosz' => $bad->amount_grosz + 1]);
    $duplicate = Order::factory()->paidAtReception()->create();
    Payment::factory()->count(2)->create(['order_id' => $duplicate->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed',
        'confirmed_at' => now(), 'confirmed_by_user_id' => $this->staff->id]);
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.salesCount', 1)->where('dashboard.salesLabel', '123,45 zł'));
    expect($missing->fresh()->status->value)->toBe('paid')->and($paid->membership->starts_on)->toBeNull();
});

test('report sums integer grosz above JavaScript safe precision without floating-point rounding', function () {
    dashboardReceipt(9007199254740993);
    dashboardReceipt(1);
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.salesCount', 2)->where('dashboard.salesLabel', '90 071 992 547 409,94 zł')->missing('dashboard.amount_grosz'));
});

test('sales date range uses half-open UTC bounds for an inclusive Warsaw day across DST', function (string $day, string $before, string $start, string $last, string $after) {
    dashboardReceipt(100, paidAt: $before);
    dashboardReceipt(200, paidAt: $start);
    dashboardReceipt(300, paidAt: $last);
    dashboardReceipt(400, paidAt: $after);
    $this->actingAs($this->admin)->get(route('admin.dashboard', ['from' => $day, 'to' => $day]))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.salesCount', 2)->where('dashboard.salesLabel', '5,00 zł'));
})->with([
    ['2026-03-29', '2026-03-28T22:59:59Z', '2026-03-28T23:00:00Z', '2026-03-29T21:59:59Z', '2026-03-29T22:00:00Z'],
    ['2026-10-25', '2026-10-24T21:59:59Z', '2026-10-24T22:00:00Z', '2026-10-25T22:59:59Z', '2026-10-25T23:00:00Z'],
]);

test('recent orders are bounded newest first include pending states and omit contacts and provider data', function () {
    Order::factory()->count(12)->create();
    $last = Order::latest('id')->firstOrFail();
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('dashboard.recentOrders', 10)->where('dashboard.recentOrders.0.reference', $last->public_reference)
        ->where('dashboard.recentOrders.0.state', 'awaiting_payment')->missing('dashboard.recentOrders.0.holder_email')
        ->missing('dashboard.recentOrders.0.provider_token')->missing('dashboard.recentOrders.0.plan_snapshot'));
});

test('dashboard query count stays bounded as recent order candidates grow', function () {
    Order::factory()->count(30)->create();
    $selects = [];
    DB::listen(function (QueryExecuted $query) use (&$selects) {
        if (str_starts_with(strtolower($query->sql), 'select')) {
            $selects[] = $query->sql;
        }
    });
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
    expect(count($selects))->toBeLessThanOrEqual(12);
});

test('invalid date ranges are rejected', function (array $range) {
    $this->actingAs($this->admin)->get(route('admin.dashboard', $range))->assertSessionHasErrors();
})->with([[['from' => 'bad']], [['from' => '2026-02-30']], [['from' => '2026-11-26', 'to' => '2026-11-01']]]);

test('role revocation blocks an existing administrator session from metrics', function () {
    $this->actingAs($this->admin);
    DB::table('users')->where('id', $this->admin->id)->update(['role' => 'employee']);
    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('verified online payment contributes once while its issued pass still awaits activation', function () {
    config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false,
        'merchant_id' => 123, 'pos_id' => 456, 'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/platnosc/powrot', 'status_url' => 'https://merchant.example.test/payments/przelewy24/notification']]);
    $regulation = new RegulationVersion;
    $regulation->forceFill(['version_number' => 1, 'body' => '<p>Regulamin testowy.</p>', 'published_at' => now(), 'created_by_user_id' => $this->admin->id])->save();
    $order = Order::factory()->create(['membership_plan_id' => MembershipPlan::factory()->create(['price_grosz' => 22300])->id,
        'accepted_regulation_version_id' => $regulation->id, 'regulation_accepted_at' => now(), 'recipient_email' => 'holder@example.test']);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456, 'registration_started_at' => now()]);
    $data = ['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id, 'amount' => 22300,
        'originAmount' => 22300, 'currency' => 'PLN', 'orderId' => 987654, 'methodId' => 25, 'statement' => 'metrics'];
    $data['sign'] = hash('sha384', json_encode([...$data, 'crc' => 'fake-crc-secret']));
    Http::fake(['*' => Http::response(['data' => ['status' => 'success'], 'responseCode' => 0])]);
    $notification = Przelewy24Notification::fromArray($data);
    app(VerifyPrzelewy24Payment::class)->handle($notification);
    app(VerifyPrzelewy24Payment::class)->handle($notification);
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.activeMemberships', 0)->where('dashboard.salesCount', 1)->where('dashboard.salesLabel', '223,00 zł'));
    Http::assertSentCount(1);
});

test('customer metric counts every registered customer account independently of payment verification or suspension', function () {
    User::factory()->create();
    User::factory()->unverified()->create();
    User::factory()->suspended()->create();
    User::factory()->employee()->create();
    User::factory()->admin()->create();
    dashboardReceipt(); // Guest purchase does not create a registered client.
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.customerAccounts', 3));
});
