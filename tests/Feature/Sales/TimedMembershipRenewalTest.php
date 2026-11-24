<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Memberships\RenewPaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-01T12:00:00Z'));
    $this->actor = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->create();
    $this->sourceOrder = timedRenewalOrder($this->plan, $this->actor);
    $this->source = app(IssuePaidMembership::class)->handle($this->sourceOrder->id, null);
    app(ActivateMembership::class)->handle($this->actor, $this->source->id);
    $this->source->refresh();
    $this->travelTo(CarbonImmutable::parse('2027-01-20T12:00:00Z'));
});

function timedRenewalOrder(MembershipPlan $plan, User $actor, array $data = []): Order
{
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'created_by_user_id' => $actor->id, ...$data]);
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id]);

    return $order;
}

test('early time renewal starts after expiry and immediately records a future PDF request', function () {
    $order = timedRenewalOrder($this->plan, $this->actor);
    $before = $this->source->getAttributes();
    $renewal = app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id)->refresh();
    expect($renewal->starts_on->toDateString())->toBe('2027-02-01')->and($renewal->expires_on->toDateString())->toBe('2027-02-28')
        ->and($renewal->activated_at)->toBeNull()->and($renewal->activated_by_user_id)->toBeNull()
        ->and($renewal->renewed_from_id)->toBe($this->source->id)->and($renewal->renewal_root_membership_id)->toBe($this->source->id)
        ->and($this->source->fresh()->getAttributes())->toBe($before)->and($renewal->deliveries()->sole()->recipient_email)->toBe($order->recipient_email)
        ->and(AuditLog::where('action', 'membership.renewed')->sole()->subject_id)->toBe($renewal->id);
});

test('multiple early purchases form consecutive periods and replay creates nothing', function () {
    $action = app(RenewPaidMembership::class);
    $firstOrder = timedRenewalOrder($this->plan, $this->actor);
    $first = $action->handle($this->actor, $firstOrder->id, $this->source->id);
    $secondOrder = timedRenewalOrder($this->plan, $this->actor);
    $second = $action->handle($this->actor, $secondOrder->id, $this->source->id)->refresh();
    expect($second->renewed_from_id)->toBe($first->id)->and($second->starts_on->toDateString())->toBe('2027-03-01')
        ->and($second->expires_on->toDateString())->toBe('2027-03-31');
    $before = $second->getAttributes();
    $this->travel(5)->months();
    expect($action->handle($this->actor, $secondOrder->id, $this->source->id)->getAttributes())->toBe($before)
        ->and(Membership::count())->toBe(3)->and(DocumentDelivery::count())->toBe(3)->and(AuditLog::where('action', 'membership.renewed')->count())->toBe(2);
});

test('renewal uses the newly purchased duration without changing past terms', function () {
    $this->plan->forceFill(['duration_months' => 3, 'price_grosz' => 30000])->save();
    $order = timedRenewalOrder($this->plan, $this->actor);
    $renewal = app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id);
    expect($renewal->expires_on->toDateString())->toBe('2027-04-30')->and($renewal->plan_snapshot['price_grosz'])->toBe(30000)
        ->and($this->source->fresh()->plan_snapshot['price_grosz'])->toBe(15000);
});

test('unpaid different holder mixed plan expired and pending activation sources are blocked', function (string $case) {
    $data = $case === 'holder' ? ['holder_first_name' => 'Inna osoba'] : [];
    $plan = $case === 'plan' ? MembershipPlan::factory()->create() : $this->plan;
    $order = timedRenewalOrder($plan, $this->actor, $data);
    $source = $this->source;
    if ($case === 'unpaid') {
        $order->forceFill(['status' => 'pending'])->save();
    }
    if ($case === 'expired') {
        $this->travelTo(CarbonImmutable::parse('2027-02-01T12:00:00Z'));
    }
    if ($case === 'pending') {
        $source = app(IssuePaidMembership::class)->handle(timedRenewalOrder($this->plan, $this->actor)->id, null);
    }
    expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $order->id, $source->id))->toThrow(ValidationException::class);
    expect(Membership::where('order_id', $order->id)->exists())->toBeFalse()->and(AuditLog::where('action', 'membership.renewed')->count())->toBe(0);
})->with(['unpaid', 'holder', 'plan', 'expired', 'pending']);

test('customer and stale suspended staff cannot renew', function (string $role) {
    $order = timedRenewalOrder($this->plan, $this->actor);
    $actor = $role === 'customer' ? User::factory()->create() : $this->actor;
    if ($role === 'suspended') {
        $actor->fresh()->forceFill(['suspended_at' => now()])->save();
    }
    expect(fn () => app(RenewPaidMembership::class)->handle($actor, $order->id, $this->source->id))->toThrow(AuthorizationException::class);
})->with(['customer', 'suspended']);

test('audit failure rolls back renewal and its PDF outbox', function () {
    $order = timedRenewalOrder($this->plan, $this->actor);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(RenewPaidMembership::class)->handle($this->actor, $order->id, $this->source->id))->toThrow(RuntimeException::class);
    expect(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1);
});
