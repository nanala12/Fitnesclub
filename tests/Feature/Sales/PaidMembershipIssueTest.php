<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Orders\CreateOnlineOrder;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23T12:00:00Z'));
    $this->admin = User::factory()->admin()->create();
    $this->plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true, 'is_public' => true]);
    $regulation = new RegulationVersion;
    $regulation->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now()->subHour(), 'created_by_user_id' => $this->admin->id])->save();
    $this->order = app(CreateOnlineOrder::class)->handle(null, [
        'membership_plan_id' => $this->plan->id, 'plan_revision' => 0, 'for_other_person' => true,
        'purchaser' => ['first_name' => 'Kupujący', 'last_name' => 'Testowy', 'email' => 'buyer@example.test', 'phone' => '+48600111222'],
        'holder' => ['first_name' => 'Posiadacz', 'last_name' => 'Testowy', 'email' => 'holder@example.test', 'phone' => '+48600333444'],
        'accept_regulations' => true, 'accepted_regulation_version_id' => $regulation->id,
    ]);
});

/** A fake trusted confirmation record, never a production payment adapter. */
function fakeIssuePayment(Order $order, array $overrides = []): Payment
{
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    return Payment::factory()->create([
        'order_id' => $order->id, 'status' => PaymentStatus::Confirmed, 'confirmed_at' => now(),
        'provider' => $order->sales_channel === SalesChannel::Online ? PaymentProvider::Przelewy24 : PaymentProvider::Reception,
        'method' => $order->payment_method, 'confirmed_by_user_id' => $order->created_by_user_id,
        'provider_session_id' => 'fake-session-'.Str::uuid(), 'provider_transaction_id' => 'fake-transaction-'.$order->id,
        ...$overrides,
    ]);
}

function expectIssueBlocked(Order $order, string $field = 'payment'): void
{
    try {
        app(IssuePaidMembership::class)->handle($order->id, null);
        test()->fail('Expected issue validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(0);
}

test('confirmed online payment issues one pending pass and durable holder PDF request', function () {
    fakeIssuePayment($this->order);
    $membership = app(IssuePaidMembership::class)->handle($this->order->id, null);
    $membership->refresh();
    expect($membership->order_id)->toBe($this->order->id)->and($membership->holder_user_id)->toBeNull()
        ->and($membership->holder_first_name)->toBe('Posiadacz')->and($membership->holder_email)->toBe('holder@example.test')
        ->and($membership->holder_phone)->toBe('+48600333444')->and($membership->plan_snapshot)->toBe($this->order->fresh()->plan_snapshot)
        ->and($membership->starts_on)->toBeNull()->and($membership->expires_on)->toBeNull()->and($membership->activated_at)->toBeNull()
        ->and($membership->activated_by_user_id)->toBeNull()->and($membership->renewed_from_id)->toBeNull()
        ->and($membership->entries_granted)->toBe(8)->and($membership->entries_remaining)->toBe(8)
        ->and($membership->entries()->count())->toBe(0);
    $delivery = DocumentDelivery::sole();
    expect($delivery->membership_id)->toBe($membership->id)->and($delivery->recipient_email)->toBe('holder@example.test')
        ->and($delivery->kind)->toBe('membership_pdf')->and($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->sent_at)->toBeNull()->and($delivery->storage_path)->toBeNull()
        ->and($delivery->attempts()->count())->toBe(0);
    $audit = AuditLog::where('action', 'membership.issued')->sole();
    expect($audit->subject_type)->toBe('membership')->and($audit->subject_id)->toBe($membership->id)
        ->and($audit->actor_type)->toBe('system')->and($audit->actor_user_id)->toBeNull()
        ->and($audit->new_values)->toBe(['price_grosz' => 15000]);
    expect(json_encode($audit->getAttributes()))->not->toContain('holder@example.test', 'buyer@example.test', '+48600333444', 'Posiadacz');
});

test('timed passes use purchased terms and stay undated when current catalog changes', function () {
    $plan = MembershipPlan::factory()->create(['is_active' => true, 'is_public' => true, 'duration_months' => 3]);
    $order = Order::factory()->create(['membership_plan_id' => $plan->id, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    fakeIssuePayment($order);
    $plan->forceFill(['name' => 'Nowa oferta', 'price_grosz' => 99000, 'duration_months' => 1, 'is_active' => false, 'is_public' => false])->save();
    $membership = app(IssuePaidMembership::class)->handle($order->id, null);
    expect($membership->plan_snapshot['duration_months'])->toBe(3)->and($membership->plan_snapshot['price_grosz'])->toBe(15000)
        ->and($membership->entries_granted)->toBeNull()->and($membership->entries_remaining)->toBeNull()
        ->and($membership->starts_on)->toBeNull()->and($membership->expires_on)->toBeNull();
});

test('registered self purchase preserves account ownership and sends PDF to the purchaser', function () {
    $account = User::factory()->create();
    $order = app(CreateOnlineOrder::class)->handle($account, [
        'membership_plan_id' => $this->plan->id, 'plan_revision' => 0, 'for_other_person' => false,
        'purchaser' => ['first_name' => 'Klient', 'last_name' => 'Testowy', 'email' => $account->email, 'phone' => $account->phone],
        'accept_regulations' => true, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id,
    ]);
    fakeIssuePayment($order);
    $membership = app(IssuePaidMembership::class)->handle($order->id, null);
    expect($membership->holder_user_id)->toBe($account->id)->and(DocumentDelivery::sole()->recipient_email)->toBe($account->email);
});

test('replays return the same pass and never reset balances audit or delivery retry state', function () {
    fakeIssuePayment($this->order);
    $action = app(IssuePaidMembership::class);
    $first = $action->handle($this->order->id, null);
    $first->forceFill(['entries_remaining' => 5])->save();
    $delivery = DocumentDelivery::sole();
    $delivery->forceFill(['status' => DeliveryStatus::Failed])->save();
    $before = $first->refresh()->getAttributes();
    $this->travel(2)->days();
    $second = $action->handle($this->order->id, null);
    expect($second->id)->toBe($first->id)->and($second->getAttributes())->toBe($before)
        ->and(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
        ->and(DocumentDelivery::sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(1);
});

test('pending failed cancelled and unknown payment states cannot issue a pass', function (string $status) {
    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    Payment::factory()->create(['order_id' => $this->order->id, 'status' => $status]);
    expectIssueBlocked($this->order);
})->with(['initiated', 'pending', 'failed', 'cancelled', 'unknown']);

test('an order marked paid without a confirmed payment cannot issue a pass', function () {
    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    expectIssueBlocked($this->order);
});

test('a confirmed payment alone cannot issue for an unpaid order', function (string $status) {
    fakeIssuePayment($this->order);
    $this->order->forceFill(['status' => $status])->save();
    expectIssueBlocked($this->order);
})->with(['pending', 'failed', 'cancelled']);

test('mismatching payment and ambiguous multiple confirmations cannot issue', function (string $mismatch) {
    $overrides = match ($mismatch) {
        'amount' => ['amount_grosz' => 15001], 'method' => ['method' => 'cash'],
        'provider' => ['provider' => 'reception', 'confirmed_by_user_id' => $this->admin->id], default => [],
    };
    fakeIssuePayment($this->order, $overrides);
    if ($mismatch === 'multiple') {
        fakeIssuePayment($this->order);
    }
    expectIssueBlocked($this->order);
})->with(['amount', 'method', 'provider', 'multiple']);

test('payment for a different order never authorizes this order', function () {
    $other = Order::factory()->create(['accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    fakeIssuePayment($other);
    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    expectIssueBlocked($this->order);
});

test('reception cash and card confirmations issue without activating and retain staff audit', function (string $method) {
    $order = Order::factory()->paidAtReception()->create(['payment_method' => $method, 'created_by_user_id' => $this->admin->id]);
    fakeIssuePayment($order);
    $membership = app(IssuePaidMembership::class)->handle($order->id, $this->admin);
    expect($membership->activated_at)->toBeNull()->and($membership->starts_on)->toBeNull()
        ->and(AuditLog::where('action', 'membership.issued')->sole()->actor_user_id)->toBe($this->admin->id);
})->with(['cash', 'card']);

test('invalid purchased snapshots cannot produce balances or passes', function (array $change) {
    $snapshot = [...$this->order->plan_snapshot, ...$change];
    $order = Order::factory()->create(['membership_plan_id' => $this->plan->id, 'plan_snapshot' => $snapshot,
        'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    fakeIssuePayment($order);
    expectIssueBlocked($order, 'order');
})->with([
    [['price_grosz' => 1]], [['snapshot_version' => 2]], [['kind' => 'unknown']], [['included_entries' => -1]],
    [['included_entries' => 0]], [['duration_months' => 1]], [['included_entries' => '8']],
    [['currency' => 'EUR']], [['name' => '']], [['kind' => 'time', 'duration_months' => 0, 'included_entries' => null]],
]);

test('non staff unsaved and stale suspended actors cannot issue', function (string $role) {
    fakeIssuePayment($this->order);
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'unverified' => User::factory()->employee()->unverified()->create(),
        'unsaved' => new User, default => User::factory()->employee()->create(),
    };
    if ($role === 'suspended') {
        $actor->fresh()->forceFill(['suspended_at' => now()])->save();
    }
    if ($role === 'unsaved') {
        $actor->forceFill(['id' => $this->admin->id]);
    }
    expect(fn () => app(IssuePaidMembership::class)->handle($this->order->id, $actor))->toThrow(AuthorizationException::class);
    expect(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0);
})->with(['customer', 'unverified', 'unsaved', 'suspended']);

test('employee may issue an already verified payment', function () {
    fakeIssuePayment($this->order);
    $employee = User::factory()->employee()->create();
    app(IssuePaidMembership::class)->handle($this->order->id, $employee);
    expect(AuditLog::where('action', 'membership.issued')->sole()->actor_user_id)->toBe($employee->id);
});

test('audit failure rolls back pass and delivery and leaves verified payment untouched', function () {
    fakeIssuePayment($this->order);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(IssuePaidMembership::class)->handle($this->order->id, null))->toThrow(RuntimeException::class);
    expect(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid)->and(Payment::sole()->status)->toBe(PaymentStatus::Confirmed);
});

test('delivery persistence failure rolls back pass and a retry succeeds once', function () {
    fakeIssuePayment($this->order);
    DocumentDelivery::created(fn () => throw new RuntimeException('Test delivery failure'));
    try {
        expect(fn () => app(IssuePaidMembership::class)->handle($this->order->id, null))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.DocumentDelivery::class);
    }
    expect(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(0);
    app(IssuePaidMembership::class)->handle($this->order->id, null);
    expect(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1);
});

test('outer payment transaction rollback also removes issue audit and outbox', function () {
    try {
        DB::transaction(function () {
            fakeIssuePayment($this->order);
            app(IssuePaidMembership::class)->handle($this->order->id, null);
            throw new RuntimeException('Test outer rollback');
        });
    } catch (RuntimeException) {
    }
    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)->and(Payment::count())->toBe(0)
        ->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(0);
});

test('audit reader labels membership without leaking holder identity', function () {
    fakeIssuePayment($this->order);
    $membership = app(IssuePaidMembership::class)->handle($this->order->id, null);
    $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action', 'Wydano opłacony karnet')->where('entries.data.0.subjectLabel', 'Karnet #'.$membership->id));
});

test('simultaneous verified notifications issue one pass audit and delivery on MySQL', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrent row locking requires MySQL.');
    }
    fakeIssuePayment($this->order);
    $barrier = tempnam(sys_get_temp_dir(), 'paid-membership-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password']];
    $processes = [];
    try {
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/Sales/issue-membership.php'), (string) $this->order->id, $barrier, (string) $worker], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 8;
        while ((! file_exists($barrier.'.0') || ! file_exists($barrier.'.1')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($barrier.'.0') && file_exists($barrier.'.1'))->toBeTrue('Both issue workers must be ready.');
        touch($barrier);
        $ids = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $ids[] = trim($process->getOutput());
        }
        expect($ids[0])->toBe($ids[1])->and(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)
            ->and(AuditLog::where('action', 'membership.issued')->count())->toBe(1)
            ->and(Membership::sole()->starts_on)->toBeNull()->and(Membership::sole()->entries_remaining)->toBe(8);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach ([$barrier, $barrier.'.0', $barrier.'.1'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
