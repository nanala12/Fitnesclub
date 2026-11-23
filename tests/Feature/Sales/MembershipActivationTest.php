<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditPayload;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-30T23:30:00.123456Z'));
    $this->employee = User::factory()->employee()->create();
});

function activationFixture(bool $entries = false): Membership
{
    $plan = $entries ? MembershipPlan::factory()->entries(8)->create() : MembershipPlan::factory()->create();
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id]);
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);

    return app(IssuePaidMembership::class)->handle($order->id, null);
}

function assertActivationBlocked(Membership $membership, User $actor, string $field = 'payment'): void
{
    try {
        app(ActivateMembership::class)->handle($actor, $membership->id);
        test()->fail('Expected activation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($membership->fresh()->activated_at)->toBeNull()
        ->and(AuditLog::where('action', 'membership.activated')->where('subject_id', $membership->id)->count())->toBe(0);
}

test('first visit activates time pass from Warsaw date with snapshot expiry and staff audit', function () {
    $membership = activationFixture();
    $membership->order->plan->forceFill(['name' => 'Zmieniona oferta', 'duration_months' => 6, 'price_grosz' => 99000, 'is_active' => false])->save();
    $result = app(ActivateMembership::class)->handle($this->employee, $membership->id)->refresh();
    expect($result->starts_on->toDateString())->toBe('2027-01-31')->and($result->expires_on->toDateString())->toBe('2027-02-28')
        ->and($result->activated_at->format('Y-m-d H:i:s.u'))->toBe('2027-01-30 23:30:00.123456')
        ->and($result->activated_by_user_id)->toBe($this->employee->id)->and($result->entries()->count())->toBe(0);
    $audit = AuditLog::where('action', 'membership.activated')->sole();
    expect($audit->actor_user_id)->toBe($this->employee->id)->and($audit->subject_id)->toBe($membership->id)
        ->and($audit->new_values)->toBe(['starts_on' => '2027-01-31', 'expires_on' => '2027-02-28']);
    expect(json_encode($audit->getAttributes()))->not->toContain($membership->holder_phone, $membership->holder_email);
    expect(DocumentDelivery::count())->toBe(1)->and(DocumentDelivery::sole()->attempts()->count())->toBe(0);
});

test('entry activation records first visit without expiry consuming or granting entries', function () {
    $membership = activationFixture(true);
    $result = app(ActivateMembership::class)->handle($this->employee, $membership->id)->refresh();
    expect($result->starts_on->toDateString())->toBe('2027-01-31')->and($result->expires_on)->toBeNull()
        ->and($result->activated_at)->not->toBeNull()->and($result->activated_by_user_id)->toBe($this->employee->id)
        ->and($result->entries_granted)->toBe(8)->and($result->entries_remaining)->toBe(8)->and($result->entries()->count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.activated')->sole()->new_values)->toBe(['starts_on' => '2027-01-31']);
});

test('activation follows calendar rules around month ends and both DST transitions', function (string $instant, string $start, string $expiry) {
    $membership = activationFixture();
    $this->travelTo(CarbonImmutable::parse($instant));
    $result = app(ActivateMembership::class)->handle($this->employee, $membership->id);
    expect($result->starts_on->toDateString())->toBe($start)->and($result->expires_on->toDateString())->toBe($expiry);
})->with([
    ['2028-01-31T12:00:00Z', '2028-01-31', '2028-02-29'],
    ['2027-04-30T12:00:00Z', '2027-04-30', '2027-05-29'],
    ['2027-02-28T12:00:00Z', '2027-02-28', '2027-03-27'],
    ['2027-03-28T00:30:00Z', '2027-03-28', '2027-04-27'],
    ['2027-03-28T01:30:00Z', '2027-03-28', '2027-04-27'],
    ['2027-10-31T00:30:00Z', '2027-10-31', '2027-11-30'],
    ['2027-10-31T01:30:00Z', '2027-10-31', '2027-11-30'],
    ['2027-07-30T22:30:00Z', '2027-07-31', '2027-08-30'],
]);

test('repeated activation and payment replay preserve original dates balances and actor', function (bool $entries) {
    $membership = activationFixture($entries);
    $action = app(ActivateMembership::class);
    $first = $action->handle($this->employee, $membership->id)->refresh();
    $before = $first->getAttributes();
    $this->travel(2)->months();
    $admin = User::factory()->admin()->create();
    expect($action->handle($admin, $membership->id)->getAttributes())->toBe($before)
        ->and(app(IssuePaidMembership::class)->handle($membership->order_id, null)->getAttributes())->toBe($before)
        ->and(AuditLog::where('action', 'membership.activated')->count())->toBe(1)->and(DocumentDelivery::count())->toBe(1);
})->with([false, true]);

test('activation blocks customers unverified suspended unsaved and deleted staff', function (string $role) {
    $membership = activationFixture();
    $actor = match ($role) {
        'customer' => User::factory()->create(), 'unverified' => User::factory()->employee()->unverified()->create(),
        'unsaved' => (new User)->forceFill(['id' => $this->employee->id]), default => User::factory()->employee()->create(),
    };
    if ($role === 'suspended') {
        $actor->fresh()->forceFill(['suspended_at' => now()])->save();
    }
    if ($role === 'deleted') {
        $actor->fresh()->delete();
    }
    expect(fn () => app(ActivateMembership::class)->handle($actor, $membership->id))->toThrow(AuthorizationException::class);
    expect($membership->fresh()->activated_at)->toBeNull()->and(AuditLog::where('action', 'membership.activated')->count())->toBe(0);
})->with(['customer', 'unverified', 'suspended', 'unsaved', 'deleted']);

test('unpaid cancelled or failed orders cannot activate an existing pass', function (string $status) {
    $membership = activationFixture();
    $membership->order->forceFill(['status' => $status])->save();
    assertActivationBlocked($membership, $this->employee);
})->with(['pending', 'failed', 'cancelled']);

test('non confirmed or mismatching payments cannot activate', function (string $status) {
    $membership = activationFixture();
    Payment::sole()->forceFill(['status' => $status])->save();
    assertActivationBlocked($membership, $this->employee);
})->with(['pending', 'failed', 'cancelled', 'unknown']);

test('scheduled renewal cannot be moved to a first visit date', function () {
    $previous = activationFixture();
    app(ActivateMembership::class)->handle($this->employee, $previous->id);
    $renewal = activationFixture();
    // Fixture for the future supported renewal path, not an invented automatic renewal rule.
    DB::table('memberships')->where('id', $renewal->id)->update(['renewed_from_id' => $previous->id, 'starts_on' => '2027-03-01', 'expires_on' => '2027-03-31']);
    assertActivationBlocked($renewal, $this->employee, 'membership');
    expect($renewal->fresh()->starts_on->toDateString())->toBe('2027-03-01');
});

test('activation and audit roll back together on failure and retry succeeds', function () {
    $membership = activationFixture(true);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(ActivateMembership::class)->handle($this->employee, $membership->id))->toThrow(RuntimeException::class);
    expect($membership->fresh()->activated_at)->toBeNull()->and($membership->fresh()->starts_on)->toBeNull()
        ->and($membership->fresh()->entries_remaining)->toBe(8);
    $this->app->forgetInstance(RecordAudit::class);
    $this->app->forgetInstance(ActivateMembership::class);
    app(ActivateMembership::class)->handle($this->employee, $membership->id);
    expect(AuditLog::where('action', 'membership.activated')->count())->toBe(1);
});

test('activated non expiring dates cannot be changed even from a stale instance', function () {
    $stale = activationFixture(true);
    app(ActivateMembership::class)->handle($this->employee, $stale->id);
    expect(fn () => $stale->forceFill(['expires_on' => '2027-12-31'])->save())->toThrow(LogicException::class);
    expect($stale->fresh()->expires_on)->toBeNull();
});

test('admin audit reader displays activation dates without holder contact', function () {
    $membership = activationFixture();
    $admin = User::factory()->admin()->create();
    app(ActivateMembership::class)->handle($admin, $membership->id);
    $this->actingAs($admin)->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action', 'Aktywowano karnet przy pierwszej wizycie')->where('entries.data.0.changes.0.after', '31.01.2027')
        ->where('entries.data.0.changes.1.after', '28.02.2027'));
});

test('audit date allowlist rejects invalid dates and contact payloads', function () {
    expect(app(AuditPayload::class)->values(['starts_on' => '2027-02-29', 'expires_on' => 'private@example.test', 'activated_at' => 'secret']))->toBe([]);
});
