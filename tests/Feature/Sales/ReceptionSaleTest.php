<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Orders\CreateReceptionOrder;
use App\Actions\Payments\ConfirmReceptionPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipAdjustment;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditPayload;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24T12:30:00.123456Z'));
    $this->seller = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true, 'is_public' => true, 'price_grosz' => 12050]);
    $this->data = [
        'request_id' => (string) Str::uuid(), 'membership_plan_id' => $this->plan->id, 'plan_revision' => 0,
        'payment_method' => 'cash', 'for_other_person' => false,
        'purchaser' => ['first_name' => ' Ania ', 'last_name' => ' Testowa ', 'email' => ' BUYER@example.test ', 'phone' => '0048 (600) 111-222'],
    ];
});

function assertReceptionInvalid(User $seller, array $data, string $field): void
{
    try {
        app(CreateReceptionOrder::class)->handle($seller, $data);
        test()->fail('Expected reception sale validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)->and(Membership::count())->toBe(0);
}

test('reception cash and card sales snapshot the server price and await first visit', function (string $method) {
    $users = User::count();
    $order = app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'payment_method' => $method]);
    expect($order->status)->toBe(OrderStatus::Pending)->and($order->paid_at)->toBeNull()
        ->and($order->created_by_user_id)->toBe($this->seller->id)->and($order->sales_channel)->toBe(SalesChannel::Reception)
        ->and($order->payment_method)->toBe(PaymentMethod::from($method))->and($order->amount_grosz)->toBe(12050)
        ->and($order->holder_phone)->toBe('+48600111222')->and($order->holder_email)->toBe('buyer@example.test')
        ->and($order->accepted_regulation_version_id)->toBeNull()->and(Payment::count())->toBe(0)->and(Membership::count())->toBe(0);
    $membership = app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    $payment = Payment::sole();
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->provider)->toBe(PaymentProvider::Reception)->and($payment->method)->toBe(PaymentMethod::from($method))
        ->and($payment->amount_grosz)->toBe(12050)->and($payment->confirmed_by_user_id)->toBe($this->seller->id)
        ->and($payment->confirmed_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-24 12:30:00.123456')
        ->and($order->fresh()->paid_at->equalTo($payment->confirmed_at))->toBeTrue()
        ->and($membership->starts_on)->toBeNull()->and($membership->expires_on)->toBeNull()->and($membership->activated_at)->toBeNull()
        ->and($membership->entries_remaining)->toBe(8)->and($membership->holder_user_id)->toBeNull()
        ->and(MembershipEntry::count())->toBe(0)->and(DocumentDelivery::sole()->recipient_email)->toBe('buyer@example.test')
        ->and(User::count())->toBe($users);
    $audit = AuditLog::where('action', 'reception.payment_confirmed')->sole();
    expect($audit->actor_user_id)->toBe($this->seller->id)->and($audit->new_values)->toMatchArray(['payment_method' => $method, 'sales_channel' => 'reception', 'price_grosz' => 12050]);
    expect(json_encode($audit->getAttributes()))->not->toContain('buyer@example.test', '+48600111222', 'Ania');
})->with(['cash', 'card']);

test('new order and confirmation retries preserve one order payment pass and delivery', function () {
    $create = app(CreateReceptionOrder::class);
    $confirm = app(ConfirmReceptionPayment::class);
    $order = $create->handle($this->seller, $this->data);
    $membership = $confirm->handle($this->seller, $order->id);
    $before = $membership->refresh()->getAttributes();
    $payment = Payment::sole()->getAttributes();
    $this->travel(2)->days();
    $this->plan->forceFill(['price_grosz' => 99999, 'is_active' => false, 'revision' => 1])->save();
    $sameOrder = $create->handle($this->seller, [...$this->data, 'request_id' => strtoupper($this->data['request_id'])]);
    $same = $confirm->handle(User::factory()->admin()->create(), $sameOrder->id);
    expect($sameOrder->id)->toBe($order->id)->and($same->getAttributes())->toBe($before)
        ->and(Payment::sole()->getAttributes())->toBe($payment)->and(Order::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
        ->and(AuditLog::where('action', 'reception.order_created')->count())->toBe(1)
        ->and(AuditLog::where('action', 'reception.payment_confirmed')->count())->toBe(1);
});

test('request cannot be reused with changed sale details or another seller', function (string $change) {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $input = $this->data;
    $actor = $change === 'actor' ? User::factory()->employee()->create() : $this->seller;
    if ($change === 'method') {
        $input['payment_method'] = 'card';
    }
    if ($change === 'person') {
        $input['purchaser']['first_name'] = 'Inna osoba';
    }
    if ($change === 'revision') {
        $input['plan_revision'] = 1;
    }
    expect(fn () => app(CreateReceptionOrder::class)->handle($actor, $input))->toThrow(ValidationException::class);
    expect(Order::count())->toBe(1)->and($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with(['actor', 'method', 'person', 'revision']);

test('gift holder receives the PDF and explicit account choices never mutate profiles', function () {
    $buyer = User::factory()->create();
    $holder = User::factory()->create();
    $before = $holder->refresh()->getAttributes();
    $order = app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'purchaser_user_id' => $buyer->id,
        'for_other_person' => true, 'holder_user_id' => $holder->id,
        'holder' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => ' HOLDER@example.test ', 'phone' => '+48 600 333 444']]);
    $membership = app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    expect($order->purchaser_user_id)->toBe($buyer->id)->and($membership->holder_user_id)->toBe($holder->id)
        ->and($membership->holder_first_name)->toBe('Jan')->and(DocumentDelivery::sole()->recipient_email)->toBe('holder@example.test')
        ->and($holder->fresh()->getAttributes())->toBe($before);
});

test('matching registered contact details do not claim a guest sale', function () {
    $account = User::factory()->create(['phone' => '+48600111222', 'email' => 'buyer@example.test']);
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $member = app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    expect($order->purchaser_user_id)->toBeNull()->and($member->holder_user_id)->toBeNull()->and($account->orders()->count())->toBe(0);
});

test('active hidden offers are sellable at reception while disabled or stale offers are rejected', function () {
    $this->plan->forceFill(['is_public' => false])->save();
    $this->plan->priceCategory()->firstOrFail()->forceFill(['is_published' => false])->save();
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    expect($order->amount_grosz)->toBe(12050);
    $this->plan->forceFill(['is_active' => false])->save();
    expect(fn () => app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'request_id' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->plan->forceFill(['is_active' => true, 'revision' => 1])->save();
    expect(fn () => app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'request_id' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

test('server controlled fields cannot alter the sale or invent payment confirmation', function (string $field) {
    assertReceptionInvalid($this->seller, [...$this->data, $field => 'forged'], $field);
})->with(['created_by_user_id', 'confirmed_by_user_id', 'amount_grosz', 'price_grosz', 'price', 'currency', 'plan_snapshot', 'status', 'sales_channel', 'paid_at', 'confirmed_at', 'ordered_at', 'recipient_email', 'accepted_regulation_version_id', 'regulation_accepted_at', 'accept_regulations', 'items', 'quantity', 'reception_request_id']);

test('reception fields and selected identities are validated', function (string $field, mixed $value) {
    $input = $this->data;
    data_set($input, $field, $value);
    assertReceptionInvalid($this->seller, $input, $field);
})->with([
    ['payment_method', 'przelewy24'], ['payment_method', 'free'], ['request_id', 'invalid'], ['request_id', ''],
    ['membership_plan_id', 999999], ['plan_revision', null], ['for_other_person', 'yes'],
    ['purchaser.first_name', ' '], ['purchaser.email', 'invalid'], ['purchaser.phone', '600111222'],
    ['purchaser_user_id', 999999], ['holder_user_id', 1], ['renewal_source_membership_id', 999999],
]);

test('non staff unverified stale suspended deleted and unsaved actors cannot sell or confirm', function (string $kind) {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $actor = match ($kind) {
        'customer' => User::factory()->create(), 'unverified' => User::factory()->employee()->unverified()->create(),
        'unsaved' => (new User)->forceFill(['id' => $this->seller->id]), default => User::factory()->employee()->create(),
    };
    if ($kind === 'suspended') {
        $actor->fresh()->forceFill(['suspended_at' => now()])->save();
    }
    if ($kind === 'deleted') {
        $actor->fresh()->delete();
    }
    expect(fn () => app(CreateReceptionOrder::class)->handle($actor, [...$this->data, 'request_id' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    expect(fn () => app(ConfirmReceptionPayment::class)->handle($actor, $order->id))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending)->and(Payment::count())->toBe(0)->and(Membership::count())->toBe(0);
})->with(['customer', 'unverified', 'suspended', 'deleted', 'unsaved']);

test('a second staff member may confirm while the original seller remains recorded', function () {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $other = User::factory()->employee()->create();
    app(ConfirmReceptionPayment::class)->handle($other, $order->id);
    expect($order->fresh()->created_by_user_id)->toBe($this->seller->id)->and(Payment::sole()->confirmed_by_user_id)->toBe($other->id);
});

test('online cancelled failed and inconsistent receipt orders cannot be confirmed', function (string $case) {
    $order = $case === 'online' ? Order::factory()->create() : app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    if (in_array($case, ['cancelled', 'failed'], true)) {
        $order->forceFill(['status' => $case])->save();
    }
    if ($case === 'payment') {
        Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash']);
    }
    expect(fn () => app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id))->toThrow(ValidationException::class);
    expect($order->fresh()->paid_at)->toBeNull()->and(Membership::count())->toBe(0);
})->with(['online', 'cancelled', 'failed', 'payment']);

test('pending receipt snapshots survive catalog price changes before confirmation', function () {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $this->plan->forceFill(['price_grosz' => 99000, 'included_entries' => 4])->save();
    $membership = app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    expect($membership->entries_remaining)->toBe(8)->and(Payment::sole()->amount_grosz)->toBe(12050);
});

test('create audit failure rolls back the pending order and idempotency key', function () {
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test order audit failure'));
    expect(fn () => app(CreateReceptionOrder::class)->handle($this->seller, $this->data))->toThrow(RuntimeException::class);
    expect(Order::count())->toBe(0);
});

test('issuance failure rolls back payment and pending order transition and retry confirms once', function () {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    DocumentDelivery::created(fn () => throw new RuntimeException('Test receipt outbox failure'));
    try {
        expect(fn () => app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.DocumentDelivery::class);
    }
    expect($order->fresh()->status)->toBe(OrderStatus::Pending)->and($order->fresh()->paid_at)->toBeNull()
        ->and(Payment::count())->toBe(0)->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and(AuditLog::where('action', 'reception.payment_confirmed')->count())->toBe(0);
    app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    expect(Payment::count())->toBe(1)->and(Membership::count())->toBe(1);
});

test('receipt renewal routes through approved rollover without automatic first use', function () {
    $sourceOrder = app(CreateReceptionOrder::class)->handle($this->seller, $this->data);
    $source = app(ConfirmReceptionPayment::class)->handle($this->seller, $sourceOrder->id);
    app(ActivateMembership::class)->handle($this->seller, $source->id);
    for ($index = 0; $index < 5; $index++) {
        app(RedeemMembershipEntry::class)->handle($this->seller, $source->id, (string) Str::uuid());
    }
    $order = app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'request_id' => (string) Str::uuid(), 'renewal_source_membership_id' => $source->id]);
    $renewed = app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    expect($renewed->entries_remaining)->toBe(11)->and($renewed->activated_at)->toBeNull()->and($source->fresh()->entries_remaining)->toBe(0)
        ->and(MembershipEntry::count())->toBe(5)->and(MembershipAdjustment::count())->toBe(2)->and(Payment::count())->toBe(2);
    expect(app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id)->id)->toBe($renewed->id)->and(MembershipAdjustment::count())->toBe(2);
});

test('admin audit formats receipt method channel and price without private contacts', function () {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data, 'payment_method' => 'card']);
    app(ConfirmReceptionPayment::class)->handle($this->seller, $order->id);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action', 'Potwierdzono płatność na recepcji')->where('entries.data.0.subjectLabel', 'Zamówienie #'.$order->id)
        ->where('entries.data.0.changes.0.after', 'Karta')->where('entries.data.0.changes.1.after', 'Recepcja'));
    expect(app(AuditPayload::class)->values(['payment_method' => 'secret@example.test', 'sales_channel' => ['private']]))->toBe([]);
});

test('blank optional selectors mean guest purchase and untrusted locator price is ignored', function () {
    $order = app(CreateReceptionOrder::class)->handle($this->seller, [...$this->data,
        'purchaser_user_id' => '', 'holder_user_id' => '', 'renewal_source_membership_id' => ' ']);
    $locator = clone $order;
    $locator->forceFill(['amount_grosz' => 1, 'payment_method' => 'card']);
    app(ConfirmReceptionPayment::class)->handle($this->seller, $locator);
    expect($order->fresh()->purchaser_user_id)->toBeNull()->and($order->fresh()->holder_user_id)->toBeNull()
        ->and(Payment::sole()->amount_grosz)->toBe(12050)->and(Payment::sole()->method)->toBe(PaymentMethod::Cash);
});
