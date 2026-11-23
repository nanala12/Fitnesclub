<?php

use App\Actions\Orders\CreateOnlineOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
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
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23T12:00:00Z'));
    $this->plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true, 'is_public' => true, 'price_grosz' => 12050, 'terms' => ['note' => 'Warunki zakupu']]);
    $this->regulation = new RegulationVersion;
    $this->regulation->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now()->subMinute(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $this->input = [
        'membership_plan_id' => $this->plan->id, 'plan_revision' => 0, 'for_other_person' => false,
        'purchaser' => ['first_name' => ' Ania ', 'last_name' => ' Testowa ', 'email' => ' BUYER@example.test ', 'phone' => '0048 (600) 111-222'],
        'accept_regulations' => true, 'accepted_regulation_version_id' => $this->regulation->id,
    ];
});

function checkoutRecipient(): array
{
    return ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => ' HOLDER@example.test ', 'phone' => '+48 600 333 444'];
}

function assertCheckoutInvalid(array $input, string $field): void
{
    try {
        app(CreateOnlineOrder::class)->handle(null, $input);
        test()->fail('Expected checkout validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Order::count())->toBe(0)->and(Membership::count())->toBe(0)->and(Payment::count())->toBe(0);
}

test('guest self purchase stores normalized snapshots and consent without issuing anything', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23T23:30:00.123456Z'));
    $users = User::count();
    $order = app(CreateOnlineOrder::class)->handle(null, $this->input);
    expect($order->purchaser_user_id)->toBeNull()->and($order->holder_user_id)->toBeNull()
        ->and($order->purchaser_first_name)->toBe('Ania')->and($order->purchaser_last_name)->toBe('Testowa')
        ->and($order->purchaser_email)->toBe('buyer@example.test')->and($order->purchaser_phone)->toBe('+48600111222')
        ->and($order->holder_first_name)->toBe('Ania')->and($order->holder_phone)->toBe('+48600111222')
        ->and($order->recipient_email)->toBe('buyer@example.test')->and($order->amount_grosz)->toBe(12050)
        ->and($order->currency)->toBe('PLN')->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->sales_channel)->toBe(SalesChannel::Online)->and($order->payment_method)->toBe(PaymentMethod::Przelewy24)
        ->and($order->paid_at)->toBeNull()->and($order->created_by_user_id)->toBeNull()
        ->and($order->accepted_regulation_version_id)->toBe($this->regulation->id)
        ->and($order->regulation_accepted_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-23 23:30:00.123456')
        ->and($order->ordered_at->equalTo($order->regulation_accepted_at))->toBeTrue()
        ->and($order->plan_snapshot)->toMatchArray(['snapshot_version' => 1, 'kind' => 'entries', 'price_grosz' => 12050, 'duration_months' => null, 'included_entries' => 8, 'terms' => ['note' => 'Warunki zakupu']])
        ->and(User::count())->toBe($users)->and(Membership::count())->toBe(0)->and(Payment::count())->toBe(0);
    expect($order->fresh()->regulation_accepted_at->equalTo($order->regulation_accepted_at))->toBeTrue();
});

test('guest and registered buyers can purchase for another person with PDF sent to holder', function (bool $registered) {
    $account = $registered ? User::factory()->create() : null;
    $order = app(CreateOnlineOrder::class)->handle($account, [...$this->input, 'for_other_person' => true, 'holder' => checkoutRecipient()]);
    expect($order->purchaser_user_id)->toBe($account?->id)->and($order->holder_user_id)->toBeNull()
        ->and($order->purchaser_email)->toBe('buyer@example.test')->and($order->holder_first_name)->toBe('Jan')
        ->and($order->holder_email)->toBe('holder@example.test')->and($order->holder_phone)->toBe('+48600333444')
        ->and($order->recipient_email)->toBe('holder@example.test');
})->with([false, true]);

test('registered self purchase links only the trusted session account without changing its profile', function () {
    $account = User::factory()->create();
    $before = $account->refresh()->getAttributes();
    $order = app(CreateOnlineOrder::class)->handle($account, $this->input);
    expect($order->purchaser_user_id)->toBe($account->id)->and($order->holder_user_id)->toBe($account->id)
        ->and($account->fresh()->getAttributes())->toBe($before);
});

test('matching contact details never attach guest or other person purchases to an account', function () {
    $matched = User::factory()->create(['email' => 'buyer@example.test', 'phone' => '+48600111222']);
    $action = app(CreateOnlineOrder::class);
    $guest = $action->handle(null, $this->input);
    $other = $action->handle(User::factory()->create(), [...$this->input, 'for_other_person' => true, 'holder' => $this->input['purchaser']]);
    expect($guest->purchaser_user_id)->toBeNull()->and($guest->holder_user_id)->toBeNull()
        ->and($other->holder_user_id)->toBeNull()->and($matched->orders()->count())->toBe(0)->and($matched->memberships()->count())->toBe(0);
});

test('two family orders can reuse contacts and remain separate single plan orders', function () {
    $action = app(CreateOnlineOrder::class);
    $first = $action->handle(null, [...$this->input, 'for_other_person' => true, 'holder' => checkoutRecipient()]);
    $second = $action->handle(null, [...$this->input, 'for_other_person' => true, 'holder' => [...checkoutRecipient(), 'first_name' => 'Anna']]);
    expect($first->id)->not->toBe($second->id)->and($first->public_reference)->not->toBe($second->public_reference)
        ->and($second->holder_first_name)->toBe('Anna')->and($first->holder_first_name)->toBe('Jan')
        ->and($first->holder_phone)->toBe($second->holder_phone)->and(Order::count())->toBe(2);
});

test('later account registration with guest contacts never claims existing orders', function () {
    $order = app(CreateOnlineOrder::class)->handle(null, $this->input);
    $account = User::factory()->create(['email' => 'buyer@example.test', 'phone' => '+48600111222']);
    expect($order->fresh()->purchaser_user_id)->toBeNull()->and($order->fresh()->holder_user_id)->toBeNull()
        ->and($account->orders()->count())->toBe(0);
});

test('purchased terms and identity survive catalog and profile edits', function () {
    $account = User::factory()->create();
    $order = app(CreateOnlineOrder::class)->handle($account, $this->input);
    $snapshot = $order->fresh()->plan_snapshot;
    $this->plan->forceFill(['name' => 'Nowa nazwa', 'price_grosz' => 99999, 'included_entries' => 4, 'terms' => [], 'revision' => 1])->save();
    $account->forceFill(['first_name' => 'Nowe', 'phone' => '+48600555666'])->save();
    expect($order->fresh()->plan_snapshot)->toBe($snapshot)->and($order->fresh()->amount_grosz)->toBe(12050)
        ->and($order->fresh()->purchaser_first_name)->toBe('Ania')->and($order->fresh()->holder_phone)->toBe('+48600111222');
});

test('timed order records duration without calculating activation dates', function () {
    $this->plan->forceFill(['kind' => 'time', 'duration_months' => 3, 'included_entries' => null])->save();
    $order = app(CreateOnlineOrder::class)->handle(null, $this->input);
    expect($order->plan_snapshot['duration_months'])->toBe(3)->and($order->plan_snapshot['included_entries'])->toBeNull()
        ->and(Membership::count())->toBe(0);
});

test('checkout rejects server controlled or multiple item fields', function (string $field) {
    assertCheckoutInvalid([...$this->input, $field => 'forged'], $field);
})->with(['purchaser_user_id', 'holder_user_id', 'created_by_user_id', 'amount_grosz', 'price_grosz', 'price', 'currency', 'plan_snapshot', 'status', 'sales_channel', 'payment_method', 'paid_at', 'ordered_at', 'regulation_accepted_at', 'recipient_email', 'memberships', 'items', 'quantity']);

test('checkout validates required and nested person fields', function (string $field, mixed $value) {
    $input = $this->input;
    data_set($input, $field, $value);
    assertCheckoutInvalid($input, $field);
})->with([
    ['membership_plan_id', null], ['plan_revision', null], ['plan_revision', -1], ['for_other_person', 'yes'],
    ['purchaser', 'invalid'], ['purchaser.first_name', ' '], ['purchaser.last_name', str_repeat('x', 121)],
    ['purchaser.email', 'invalid'], ['purchaser.phone', '600111222'], ['accept_regulations', false],
    ['accepted_regulation_version_id', null],
]);

test('other person requires complete holder data and self purchase rejects conflicting holder', function () {
    assertCheckoutInvalid([...$this->input, 'for_other_person' => true], 'holder');
    assertCheckoutInvalid([...$this->input, 'for_other_person' => true, 'holder' => [...checkoutRecipient(), 'email' => '']], 'holder.email');
    assertCheckoutInvalid([...$this->input, 'holder' => checkoutRecipient()], 'holder');
    assertCheckoutInvalid([...$this->input, 'purchaser' => [...$this->input['purchaser'], 'user_id' => 1]], 'purchaser');
});

test('checkout rejects unavailable plans and hidden categories', function (string $condition) {
    match ($condition) {
        'inactive' => $this->plan->forceFill(['is_active' => false])->save(),
        'hidden' => $this->plan->forceFill(['is_public' => false])->save(),
        'category' => $this->plan->priceCategory()->firstOrFail()->forceFill(['is_published' => false])->save(),
        default => $this->input['membership_plan_id'] = 999999,
    };
    assertCheckoutInvalid($this->input, 'membership_plan_id');
})->with(['inactive', 'hidden', 'category', 'missing']);

test('stale catalog revision requires reviewing current terms before order creation', function () {
    $this->plan->forceFill(['revision' => 1, 'price_grosz' => 13000])->save();
    assertCheckoutInvalid($this->input, 'plan_revision');
    $order = app(CreateOnlineOrder::class)->handle(null, [...$this->input, 'plan_revision' => 1]);
    expect($order->amount_grosz)->toBe(13000)->and($order->plan_snapshot['price_grosz'])->toBe(13000);
});

test('checkout accepts only the current published regulation', function (string $condition) {
    if ($condition === 'missing') {
        $this->input['accepted_regulation_version_id'] = 999999;
    } else {
        $next = new RegulationVersion;
        $next->forceFill(['version_number' => 2, 'body' => '<p>Nowszy regulamin.</p>', 'published_at' => $condition === 'obsolete' ? now() : null, 'created_by_user_id' => $this->regulation->created_by_user_id])->save();
        if ($condition === 'draft') {
            $this->input['accepted_regulation_version_id'] = $next->id;
        }
    }
    assertCheckoutInvalid($this->input, 'accepted_regulation_version_id');
})->with(['missing', 'draft', 'obsolete']);

test('fresh suspension blocks a stale authenticated account object', function () {
    $account = User::factory()->create();
    $account->fresh()->forceFill(['suspended_at' => now()])->save();
    expect(fn () => app(CreateOnlineOrder::class)->handle($account, $this->input))->toThrow(AuthorizationException::class);
    expect(Order::count())->toBe(0);
});

test('a failed order save rolls back without payments memberships or consent leftovers', function () {
    Order::created(fn () => throw new RuntimeException('Test persistence failure'));
    try {
        expect(fn () => app(CreateOnlineOrder::class)->handle(null, $this->input))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.Order::class);
    }
    expect(Order::count())->toBe(0)->and(Membership::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('checkout refuses to proceed without a published regulation', function () {
    DB::table('regulation_versions')->where('id', $this->regulation->id)->delete();
    assertCheckoutInvalid($this->input, 'accepted_regulation_version_id');
});

test('locking checkout reads observe newer catalog and regulation changes inside repeatable read', function (string $change) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Current locking reads require MySQL REPEATABLE READ.');
    }
    config(['database.connections.checkout_writer' => config('database.connections.mysql')]);
    try {
        DB::transaction(function () use ($change) {
            MembershipPlan::query()->get();
            RegulationVersion::query()->get();
            $writer = DB::connection('checkout_writer');
            $field = match ($change) {
                'price' => 'plan_revision', 'category' => 'membership_plan_id', default => 'accepted_regulation_version_id',
            };
            match ($change) {
                'price' => $writer->table('membership_plans')->where('id', $this->plan->id)->update(['revision' => 1, 'price_grosz' => 99999]),
                'category' => $writer->table('price_list_categories')->where('key', 'gym')->update(['is_published' => false]),
                default => $writer->table('regulation_versions')->insert(['version_number' => 2, 'body' => '<p>Aktualny regulamin.</p>', 'published_at' => now()->format('Y-m-d H:i:s'), 'created_by_user_id' => $this->regulation->created_by_user_id]),
            };
            assertCheckoutInvalid($this->input, $field);
        });
    } finally {
        DB::purge('checkout_writer');
    }
})->with(['price', 'category', 'regulation']);
