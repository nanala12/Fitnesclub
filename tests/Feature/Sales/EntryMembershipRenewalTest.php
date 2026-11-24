<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Memberships\RenewPaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipAdjustment;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-20T12:00:00Z'));
    $this->actor = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->entries(8)->create();
    $sourceOrder = entryRenewalOrder($this->plan, $this->actor);
    $this->source = app(IssuePaidMembership::class)->handle($sourceOrder->id, null);
    app(ActivateMembership::class)->handle($this->actor, $this->source->id);
    for ($index = 0; $index < 5; $index++) {
        app(RedeemMembershipEntry::class)->handle($this->actor, $this->source->id, (string) Str::uuid());
    }
    $this->source->refresh();
});

function entryRenewalOrder(MembershipPlan $plan, User $actor, array $attributes = []): Order
{
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'created_by_user_id' => $actor->id, ...$attributes]);
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id]);

    return $order;
}

test('three unused plus eight bought produces eleven immediately usable entries without a visit event', function () {
    $order = entryRenewalOrder($this->plan, $this->actor);
    $before = $this->source->getAttributes();
    $target = app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id)->refresh();
    expect($target->entries_granted)->toBe(11)->and($target->entries_remaining)->toBe(11)
        ->and($target->starts_on->toDateString())->toBe('2027-01-20')->and($target->expires_on)->toBeNull()
        ->and($target->activated_at)->toBeNull()->and($target->activated_by_user_id)->toBeNull()
        ->and($this->source->fresh()->entries_remaining)->toBe(0)->and(MembershipEntry::count())->toBe(5)
        ->and(MembershipAdjustment::orderBy('id')->pluck('delta_entries')->all())->toBe([-3, 3])
        ->and(DocumentDelivery::count())->toBe(2)->and($target->plan_snapshot['included_entries'])->toBe(8);
    foreach ($before as $field => $value) {
        if (! in_array($field, ['entries_remaining', 'updated_at'], true)) {
            expect($this->source->fresh()->getAttributes()[$field])->toBe($value);
        }
    }
    $entry = app(RedeemMembershipEntry::class)->handle($this->actor, $target->id, (string) Str::uuid());
    expect($entry->membership_id)->toBe($target->id)->and($target->fresh()->entries_remaining)->toBe(10)
        ->and(AuditLog::where('action', 'membership.activated')->count())->toBe(1);
    expect(fn () => app(RedeemMembershipEntry::class)->handle($this->actor, $this->source->id, (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('rollover replay after a use does not grant consumed or transferred entries again', function () {
    $order = entryRenewalOrder($this->plan, $this->actor);
    $action = app(RenewPaidMembership::class);
    $target = $action->handle($this->actor, $order->id, $this->source->id);
    app(RedeemMembershipEntry::class)->handle($this->actor, $target->id, (string) Str::uuid());
    $same = $action->handle($this->actor, $order->id, $this->source->id);
    expect($same->id)->toBe($target->id)->and($same->entries_remaining)->toBe(10)->and(MembershipAdjustment::count())->toBe(2)
        ->and(DocumentDelivery::count())->toBe(2)->and(AuditLog::where('action', 'membership.renewed')->count())->toBe(1);
});

test('successive purchases move the current tail balance once and preserve all earlier ledgers', function () {
    $action = app(RenewPaidMembership::class);
    $first = $action->handle($this->actor, entryRenewalOrder($this->plan, $this->actor)->id, $this->source->id);
    app(RedeemMembershipEntry::class)->handle($this->actor, $first->id, (string) Str::uuid());
    $second = $action->handle($this->actor, entryRenewalOrder($this->plan, $this->actor)->id, $this->source->id);
    expect($second->entries_remaining)->toBe(18)->and($second->renewed_from_id)->toBe($first->id)
        ->and($this->source->fresh()->entries_remaining)->toBe(0)->and($first->fresh()->entries_remaining)->toBe(0)
        ->and(MembershipAdjustment::orderBy('id')->pluck('delta_entries')->all())->toBe([-3, 3, -10, 10])
        ->and(MembershipEntry::count())->toBe(6);
});

test('confirmed four to eight transition is supported while unapproved plan changes are blocked', function () {
    $fourPlan = MembershipPlan::factory()->entries(4)->create();
    $source = app(IssuePaidMembership::class)->handle(entryRenewalOrder($fourPlan, $this->actor)->id, null);
    app(ActivateMembership::class)->handle($this->actor, $source->id);
    app(RedeemMembershipEntry::class)->handle($this->actor, $source->id, (string) Str::uuid());
    $target = app(RenewPaidMembership::class)->handle($this->actor, entryRenewalOrder($this->plan, $this->actor)->id, $source->id);
    expect($target->entries_remaining)->toBe(11);
    $otherFour = entryRenewalOrder($fourPlan, $this->actor);
    expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $otherFour->id, $target->id))->toThrow(ValidationException::class);
});

test('zero remaining entries add no duplicate movement and a newly paid allowance is available', function () {
    for ($index = 0; $index < 3; $index++) {
        app(RedeemMembershipEntry::class)->handle($this->actor, $this->source->id, (string) Str::uuid());
    }
    $target = app(RenewPaidMembership::class)->handle($this->actor, entryRenewalOrder($this->plan, $this->actor)->id, $this->source->id);
    expect($target->entries_remaining)->toBe(8)->and(MembershipAdjustment::count())->toBe(0);
});

test('guest contact match never links a renewal to a registered account', function () {
    $account = User::factory()->create(['phone' => $this->source->holder_phone, 'email' => $this->source->holder_email]);
    $order = entryRenewalOrder($this->plan, $this->actor, ['holder_user_id' => $account->id]);
    expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id))->toThrow(ValidationException::class);
    expect($this->source->fresh()->entries_remaining)->toBe(3)->and(MembershipAdjustment::count())->toBe(0);
});

test('registered holder identity is the account id even after contact profile changes', function () {
    $account = User::factory()->create();
    $order = entryRenewalOrder($this->plan, $this->actor, ['holder_user_id' => $account->id]);
    $source = app(IssuePaidMembership::class)->handle($order->id, null);
    app(ActivateMembership::class)->handle($this->actor, $source->id);
    $new = entryRenewalOrder($this->plan, $this->actor, ['holder_user_id' => $account->id, 'holder_first_name' => 'Nowe imię', 'holder_phone' => '+48600999888']);
    $target = app(RenewPaidMembership::class)->handle($this->actor, $new->id, $source->id);
    expect($target->holder_user_id)->toBe($account->id)->and($target->holder_first_name)->toBe('Nowe imię')->and($target->entries_remaining)->toBe(16);
});

test('audit failure rolls back both sides and adjustment records before safe retry', function () {
    $order = entryRenewalOrder($this->plan, $this->actor);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test rollover audit failure'));
    expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id))->toThrow(RuntimeException::class);
    expect($this->source->fresh()->entries_remaining)->toBe(3)->and(Membership::count())->toBe(1)
        ->and(MembershipAdjustment::count())->toBe(0)->and(DocumentDelivery::count())->toBe(1);
    $this->app->forgetInstance(RecordAudit::class);
    $this->app->forgetInstance(RenewPaidMembership::class);
    expect(app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id)->entries_remaining)->toBe(11);
});

test('adjustment persistence failure leaves the source allowance unchanged', function () {
    $order = entryRenewalOrder($this->plan, $this->actor);
    MembershipAdjustment::created(fn () => throw new RuntimeException('Test adjustment failure'));
    try {
        expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.MembershipAdjustment::class);
    }
    expect($this->source->fresh()->entries_remaining)->toBe(3)->and(Membership::count())->toBe(1)->and(MembershipAdjustment::count())->toBe(0);
});

test('scheduled entry renewal cannot receive a fictional expiry or first visit activation', function () {
    $target = app(RenewPaidMembership::class)->handle($this->actor, entryRenewalOrder($this->plan, $this->actor)->id, $this->source->id);
    expect(fn () => $target->forceFill(['expires_on' => '2027-12-31'])->save())->toThrow(LogicException::class);
    expect(fn () => app(ActivateMembership::class)->handle($this->actor, $target->id))->toThrow(ValidationException::class);
});

test('trusted paid issuance routes a bound renewal without a staff session', function () {
    $order = entryRenewalOrder($this->plan, $this->actor, ['renewal_source_membership_id' => $this->source->id]);
    $target = app(IssuePaidMembership::class)->handle($order->id, null);
    expect($target->entries_remaining)->toBe(11)->and($target->renewed_from_id)->toBe($this->source->id)
        ->and(MembershipAdjustment::where('kind', 'rollover_in')->sole()->performed_by_user_id)->toBeNull();
    expect(app(IssuePaidMembership::class)->handle($order->id, null)->id)->toBe($target->id)
        ->and(MembershipAdjustment::count())->toBe(2);
});

test('system cannot infer an unbound source and selected source is immutable after success', function () {
    $order = entryRenewalOrder($this->plan, $this->actor);
    expect(fn () => app(RenewPaidMembership::class)->handle(null, $order->id, $this->source->id))->toThrow(ValidationException::class);
    $target = app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id);
    expect($order->fresh()->renewal_source_membership_id)->toBe($this->source->id);
    expect(fn () => $order->fresh()->forceFill(['renewal_source_membership_id' => $target->id])->save())->toThrow(LogicException::class);
});
