<?php

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanKind;
use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use App\Models\Membership;
use App\Models\MembershipAdjustment;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('new sales factories persist typed relations with a paid pending activation pass and immediate document record', function () {
    $delivery = DocumentDelivery::factory()->create();
    $membership = $delivery->membership;
    expect($membership)->toBeInstanceOf(Membership::class)->and($membership->order->status)->toBe(OrderStatus::Paid)
        ->and($membership->starts_on)->toBeNull()->and($membership->expires_on)->toBeNull()->and($membership->activated_at)->toBeNull()
        ->and($membership->activated_by_user_id)->toBeNull()->and($membership->order->amount_grosz)->toBeInt()
        ->and($membership->order->plan->kind)->toBe(PlanKind::Time)->and($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->scheduled_for)->not->toBeNull()->and($delivery->recipient_email)->toBe($membership->order->recipient_email)
        ->and($membership->order->membership->id)->toBe($membership->id);
    $payment = Payment::factory()->for($membership->order, 'order')->create();
    expect($payment->status)->toBe(PaymentStatus::Initiated)->and($membership->order->payments()->count())->toBe(1);
    $entry = MembershipEntry::factory()->create();
    $adjustment = MembershipAdjustment::factory()->create();
    $attempt = DocumentDeliveryAttempt::factory()->for($delivery, 'delivery')->create();
    expect($entry->recordedBy)->toBeInstanceOf(User::class)->and($adjustment->membership)->toBeInstanceOf(Membership::class)->and($attempt->delivery->id)->toBe($delivery->id);
});

test('one order cannot issue multiple memberships even through raw database writes', function () {
    $membership = Membership::factory()->create();
    $duplicate = $membership->getRawOriginal();
    unset($duplicate['id']);
    expect(fn () => DB::table('memberships')->insert($duplicate))->toThrow(QueryException::class);
});

test('registered accounts and guest orders may share contacts without linking or merging guests', function () {
    $first = Order::factory()->create();
    $second = Order::factory()->create();
    expect(User::count())->toBe(0)->and($first->purchaser_user_id)->toBeNull()->and($first->holder_user_id)->toBeNull();
    $user = User::factory()->create(['email' => $first->purchaser_email, 'phone' => $first->purchaser_phone]);
    expect($first->fresh()->purchaser_user_id)->toBeNull()->and($user->orders()->count())->toBe(0)->and(Order::count())->toBe(2);
    expect(fn () => $first->forceFill(['purchaser_user_id' => $user->id])->save())->toThrow(LogicException::class);
    $registered = Order::factory()->for($user, 'purchaser')->create();
    expect($registered->holder_user_id)->toBeNull()->and($registered->purchaser->id)->toBe($user->id)->and($user->orders()->count())->toBe(1);
    $holder = User::factory()->create();
    $ownOrder = Order::factory()->for($user, 'purchaser')->for($holder, 'holder')->paidAtReception()->create();
    $pass1 = Membership::factory()->for($ownOrder, 'order')->create();
    $pass2 = Membership::factory()->for(Order::factory()->for($holder, 'holder')->paidAtReception(), 'order')->create();
    expect($holder->memberships()->count())->toBe(2)->and($pass1->holder->id)->toBe($holder->id)->and($pass1->holder_user_id)->not->toBe($user->id)->and($second->fresh()->holder_user_id)->toBeNull();
});

test('catalog edits never rewrite historical snapshots amounts or account contacts', function () {
    $membership = Membership::factory()->create();
    $order = $membership->order;
    $original = $order->plan_snapshot;
    $order->plan->forceFill(['name' => 'Nowa nazwa', 'price_grosz' => 99000])->save();
    expect($order->fresh()->plan_snapshot)->toBe($original)->and($membership->fresh()->plan_snapshot)->toBe($original)->and($order->fresh()->amount_grosz)->toBe(15000);
    expect(fn () => $order->forceFill(['amount_grosz' => 99000])->save())->toThrow(LogicException::class);
    expect(fn () => $membership->forceFill(['holder_phone' => '+48600999888'])->saveQuietly())->toThrow(LogicException::class);
});

test('integer grosz cast rejects fractional negative textual or boolean monetary values', function (mixed $value) {
    expect(fn () => MembershipPlan::factory()->create(['price_grosz' => $value]))->toThrow(InvalidArgumentException::class);
})->with([1.25, -1, '15000', true]);

test('raw negative money and unsupported currency are rejected by database constraints', function (string $field) {
    $plan = MembershipPlan::factory()->create();
    expect(fn () => DB::table('membership_plans')->where('id', $plan->id)->update([$field => $field === 'price_grosz' ? -1 : 'EUR']))->toThrow(QueryException::class);
})->with(['price_grosz', 'currency']);

test('membership date pair and entry balance constraints prevent partial periods and invalid balances', function (string $case) {
    $membership = Membership::factory()->create();
    $values = match ($case) {
        'partial' => ['starts_on' => '2026-11-20'], 'reverse' => ['starts_on' => '2026-11-20', 'expires_on' => '2026-11-19'],
        'negative' => ['entries_granted' => 8, 'entries_remaining' => -1], 'overflow' => ['entries_granted' => 8, 'entries_remaining' => 9], default => ['entries_granted' => 8, 'entries_remaining' => null],
    };
    expect(fn () => DB::table('memberships')->where('id', $membership->id)->update($values))->toThrow(QueryException::class);
})->with(['partial', 'reverse', 'negative', 'overflow', 'partial-balance']);

test('deduplication keys protect entries adjustments deliveries and delivery attempts', function (string $model) {
    $record = $model::factory()->create();
    $values = $record->getRawOriginal();
    unset($values['id']);
    expect(fn () => DB::table($record->getTable())->insert($values))->toThrow(QueryException::class);
})->with([MembershipEntry::class, MembershipAdjustment::class, DocumentDelivery::class, DocumentDeliveryAttempt::class]);

test('sales history cannot be updated through a query or deleted through model quiet and query paths', function (string $operation) {
    $order = Order::factory()->create();
    expect(fn () => match ($operation) {
        'query-update' => Order::whereKey($order->id)->update(['amount_grosz' => 1]), 'query-delete' => Order::whereKey($order->id)->delete(),
        'quiet-delete' => $order->deleteQuietly(), default => $order->delete(),
    })->toThrow(LogicException::class);
})->with(['query-update', 'query-delete', 'quiet-delete', 'delete']);

test('payment confirmation instant and membership activation period are write once', function () {
    $order = Order::factory()->paidAtReception()->create();
    expect(fn () => $order->forceFill(['paid_at' => now()->addHour()])->saveQuietly())->toThrow(LogicException::class);
    $membership = Membership::factory()->create();
    $actor = User::factory()->employee()->create();
    $membership->forceFill(['starts_on' => '2026-11-20', 'expires_on' => '2026-12-19', 'activated_at' => now(), 'activated_by_user_id' => $actor->id])->save();
    expect(fn () => $membership->forceFill(['expires_on' => '2027-01-01'])->save())->toThrow(LogicException::class);
    $payment = Payment::factory()->create();
    $payment->forceFill(['status' => PaymentStatus::Confirmed, 'confirmed_at' => now()])->save();
    expect(fn () => $payment->forceFill(['confirmed_at' => now()->addDay()])->save())->toThrow(LogicException::class);
});

test('online paid order requires a paired regulation acceptance and retains its version after later publication', function () {
    $actor = User::factory()->admin()->create();
    $version = RegulationVersion::forceCreate(['version_number' => 1, 'body' => '<p>Test regulation</p>', 'published_at' => now(), 'created_by_user_id' => $actor->id]);
    $order = Order::factory()->create();
    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['status' => 'paid', 'paid_at' => now()]))->toThrow(QueryException::class);
    $order->forceFill(['accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now(), 'status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    RegulationVersion::forceCreate(['version_number' => 2, 'body' => '<p>New regulation</p>', 'published_at' => now(), 'created_by_user_id' => $actor->id]);
    expect($order->fresh()->acceptedRegulation->id)->toBe($version->id);
    expect(fn () => $order->forceFill(['accepted_regulation_version_id' => 2])->save())->toThrow(LogicException::class);
});

test('foreign keys restrict deleting referenced plans accounts and historical orders', function (string $target) {
    $user = User::factory()->create();
    $order = Order::factory()->for($user, 'purchaser')->paidAtReception()->create();
    Membership::factory()->for($order, 'order')->create();
    [$table,$id] = match ($target) {
        'user' => ['users', $user->id], 'plan' => ['membership_plans', $order->membership_plan_id], default => ['orders', $order->id]
    };
    expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
})->with(['user', 'plan', 'order']);

test('new sales tables start empty and the public price list remains accessible', function () {
    foreach (['membership_plans', 'orders', 'payments', 'memberships', 'membership_entries', 'membership_adjustments', 'document_deliveries', 'document_delivery_attempts'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue()->and(DB::table($table)->count())->toBe(0);
    }
    $this->get(route('public.pricing'))->assertOk();
});

test('core migration rolls back and reapplies without changing existing users CMS or audit tables', function () {
    $user = User::factory()->create();
    $version = RegulationVersion::forceCreate(['version_number' => 1, 'body' => '<p>Existing CMS content</p>', 'created_by_user_id' => $user->id]);
    $migration = require database_path('migrations/2026_11_20_000000_create_membership_sales_tables.php');
    $migration->down();
    foreach (['membership_plans', 'orders', 'payments', 'memberships', 'membership_entries', 'membership_adjustments', 'document_deliveries', 'document_delivery_attempts'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(User::findOrFail($user->id)->email)->toBe($user->email)->and(RegulationVersion::findOrFail($version->id)->body)->toBe($version->body)->and(Schema::hasTable('audit_logs'))->toBeTrue();
    $migration->up();
    expect(Membership::factory()->create()->starts_on)->toBeNull()->and(User::findOrFail($user->id)->email)->toBe($user->email);
});

test('membership schema enforces activation metadata while allowing scheduled renewals without a new activation', function (string $case) {
    $membership = Membership::factory()->create();
    $actor = User::factory()->employee()->create();
    $changes = match ($case) {
        'actor-only' => ['activated_by_user_id' => $actor->id],
        'instant-only' => ['activated_at' => now()],
        'activation-without-period' => ['activated_at' => now(), 'activated_by_user_id' => $actor->id],
        default => ['starts_on' => '2026-11-20', 'expires_on' => '2026-12-19'],
    };
    expect(fn () => DB::table('memberships')->where('id', $membership->id)->update($changes))->toThrow(QueryException::class);
})->with(['actor-only', 'instant-only', 'activation-without-period', 'period-without-activation']);

test('scheduled renewal retains preceding pass and known dates without a second activation actor', function () {
    $previous = Membership::factory()->create();
    $actor = User::factory()->employee()->create();
    $previous->forceFill(['starts_on' => '2026-11-01', 'expires_on' => '2026-11-30', 'activated_at' => '2026-11-01 08:00:00', 'activated_by_user_id' => $actor->id])->save();
    $renewalOrder = Order::factory()->for($previous->plan, 'plan')->paidAtReception()->create();
    $renewal = Membership::factory()->for($renewalOrder, 'order')->create(['renewed_from_id' => $previous->id, 'starts_on' => '2026-12-01', 'expires_on' => '2026-12-31']);
    expect($renewal->activated_at)->toBeNull()->and($renewal->activated_by_user_id)->toBeNull()->and($renewal->renewedFrom->id)->toBe($previous->id)
        ->and($previous->renewals()->sole()->id)->toBe($renewal->id)->and($previous->fresh()->expires_on->toDateString())->toBe('2026-11-30');
});

test('plan constraints reject unsupported kinds missing time durations and missing entry counts', function (string $case) {
    $plan = MembershipPlan::factory()->create();
    $changes = match ($case) {
        'kind' => ['kind' => 'hybrid'], 'time' => ['duration_months' => null], 'entries' => ['kind' => 'entries', 'included_entries' => null], default => ['kind' => 'entries', 'included_entries' => 0]
    };
    expect(fn () => DB::table('membership_plans')->where('id', $plan->id)->update($changes))->toThrow(QueryException::class);
})->with(['kind', 'time', 'entries', 'zero']);

test('paid and confirmed records require timestamps and reception confirmation requires its actor', function (string $case) {
    if ($case === 'paid') {
        $order = Order::factory()->paidAtReception()->create();
        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['paid_at' => null]))->toThrow(QueryException::class);
    } else {
        $payment = Payment::factory()->create();
        $changes = $case === 'confirmed' ? ['status' => 'confirmed'] : ['provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now()];
        expect(fn () => DB::table('payments')->where('id', $payment->id)->update($changes))->toThrow(QueryException::class);
    }
})->with(['paid', 'confirmed', 'actor']);

test('sales channel payment method and regulation acceptance pair must be coherent', function (string $case) {
    $order = Order::factory()->create();
    $changes = $case === 'method' ? ['payment_method' => 'cash'] : ['regulation_accepted_at' => now()];
    expect(fn () => DB::table('orders')->where('id', $order->id)->update($changes))->toThrow(QueryException::class);
})->with(['method', 'consent']);

test('append only records reject edits and monetary snapshots reject common bulk bypasses', function (string $operation) {
    $order = Order::factory()->create();
    expect(fn () => match ($operation) {
        'upsert' => Order::upsert([$order->getRawOriginal()], ['id'], ['amount_grosz']),
        'increment' => Order::whereKey($order->id)->increment('amount_grosz'),
        'insert' => Order::insert($order->getRawOriginal()),
        default => MembershipEntry::factory()->create()->forceFill(['used_at' => now()])->saveQuietly(),
    })->toThrow(LogicException::class);
})->with(['upsert', 'increment', 'insert', 'entry-edit']);

test('historical primary identifiers and account associations cannot be changed even before payment', function () {
    $first = Order::factory()->create();
    $second = Order::factory()->create();
    expect(fn () => $first->forceFill(['id' => $second->id])->save())->toThrow(LogicException::class);
    $first->refresh();
    $holder = User::factory()->create();
    expect(fn () => $first->forceFill(['holder_user_id' => $holder->id])->save())->toThrow(LogicException::class);
    expect(Order::count())->toBe(2);
});

test('transaction instants preserve microseconds and private sales data is hidden from generic serialization', function () {
    $instant = CarbonImmutable::parse('2026-11-20 23:59:59.123456', 'UTC');
    $order = Order::factory()->create(['ordered_at' => $instant]);
    expect($order->fresh()->ordered_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-20 23:59:59.123456')
        ->and($order->toArray())->not->toHaveKeys(['purchaser_email', 'purchaser_phone', 'holder_email', 'holder_phone', 'recipient_email']);
    $delivery = DocumentDelivery::factory()->create(['storage_path' => 'private/test.pdf']);
    expect($delivery->toArray())->not->toHaveKeys(['recipient_email', 'storage_path']);
});
