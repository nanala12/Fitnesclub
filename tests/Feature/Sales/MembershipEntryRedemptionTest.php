<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\GetMembershipEntryHistory;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditPayload;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-30T23:30:00.123456Z'));
    $this->employee = User::factory()->employee()->create();
});

function redemptionFixture(User $actor, ?int $entries = 8, bool $activate = true): Membership
{
    $plan = $entries === null ? MembershipPlan::factory()->create() : MembershipPlan::factory()->entries($entries)->create();
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id]);
    Payment::factory()->create(['order_id' => $order->id, 'provider' => 'reception', 'method' => 'cash', 'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    $membership = app(IssuePaidMembership::class)->handle($order->id, null);

    return $activate ? app(ActivateMembership::class)->handle($actor, $membership->id) : $membership;
}

function assertRedemptionInvalid(User $actor, Membership $membership, string $field = 'membership', ?string $requestId = null): void
{
    $balance = $membership->fresh()->entries_remaining;
    $entries = MembershipEntry::count();
    $audits = AuditLog::where('action', 'membership.entry_used')->count();
    try {
        app(RedeemMembershipEntry::class)->handle($actor, $membership->id, $requestId ?? (string) Str::uuid());
        test()->fail('Expected entry-use validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($membership->fresh()->entries_remaining)->toBe($balance)->and(MembershipEntry::count())->toBe($entries)
        ->and(AuditLog::where('action', 'membership.entry_used')->count())->toBe($audits);
}

test('explicit entry use decreases one balance and records UTC staff identity and safe audit', function () {
    $membership = redemptionFixture($this->employee);
    $before = $membership->refresh()->getAttributes();
    $uuid = (string) Str::uuid();
    $entry = app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, $uuid)->refresh();
    expect($membership->fresh()->entries_remaining)->toBe(7)->and($membership->fresh()->entries_granted)->toBe(8)
        ->and($entry->membership_id)->toBe($membership->id)->and($entry->recorded_by_user_id)->toBe($this->employee->id)
        ->and($entry->request_id)->toBe($uuid)->and($entry->used_at->format('Y-m-d H:i:s.u'))->toBe('2027-01-30 23:30:00.123456')
        ->and($entry->created_at->equalTo($entry->used_at))->toBeTrue()->and(DocumentDelivery::count())->toBe(1);
    $after = $membership->fresh()->getAttributes();
    foreach ($before as $field => $value) {
        if (! in_array($field, ['entries_remaining', 'updated_at'], true)) {
            expect($after[$field])->toBe($value);
        }
    }
    $audit = AuditLog::where('action', 'membership.entry_used')->sole();
    expect($audit->actor_user_id)->toBe($this->employee->id)->and($audit->subject_id)->toBe($membership->id)
        ->and($audit->old_values)->toBe(['entries_remaining' => 8])->and($audit->new_values)->toBe(['entries_remaining' => 7])
        ->and($audit->metadata['request_id'])->toBe($uuid);
    expect(json_encode($audit->getAttributes()))->not->toContain($membership->holder_phone, $membership->holder_email);
});

test('activation alone consumes nothing and exhausted pass permits only replay of the same use', function () {
    $membership = redemptionFixture($this->employee, 1);
    expect($membership->entries_remaining)->toBe(1)->and(MembershipEntry::count())->toBe(0);
    $uuid = (string) Str::uuid();
    $action = app(RedeemMembershipEntry::class);
    $first = $action->handle($this->employee, $membership->id, $uuid)->refresh();
    $before = $first->getAttributes();
    $this->travel(3)->years();
    $second = $action->handle($this->employee, $membership->id, strtoupper($uuid));
    expect($second->getAttributes())->toBe($before)->and($membership->fresh()->entries_remaining)->toBe(0)
        ->and(MembershipEntry::count())->toBe(1)->and(AuditLog::where('action', 'membership.entry_used')->count())->toBe(1);
    assertRedemptionInvalid($this->employee, $membership);
});

test('separate explicit requests may consume multiple purchased entries on the same day', function () {
    $membership = redemptionFixture($this->employee, 4);
    $action = app(RedeemMembershipEntry::class);
    for ($use = 0; $use < 4; $use++) {
        $action->handle($this->employee, $membership->id, (string) Str::uuid());
    }
    expect($membership->fresh()->entries_remaining)->toBe(0)->and(MembershipEntry::count())->toBe(4);
    assertRedemptionInvalid($this->employee, $membership);
});

test('request identifiers are scoped to the original membership and staff actor', function (string $conflict) {
    $first = redemptionFixture($this->employee);
    $uuid = (string) Str::uuid();
    app(RedeemMembershipEntry::class)->handle($this->employee, $first->id, $uuid);
    $target = $conflict === 'membership' ? redemptionFixture($this->employee) : $first;
    $actor = $conflict === 'actor' ? User::factory()->employee()->create() : $this->employee;
    assertRedemptionInvalid($actor, $target, 'request_id', $uuid);
})->with(['membership', 'actor']);

test('invalid request identifiers create no ledger or balance changes', function (string $uuid) {
    assertRedemptionInvalid($this->employee, redemptionFixture($this->employee), 'request_id', $uuid);
})->with(['', 'not-a-uuid', '11111111-2222-3333-4444-555555555555@example.test', "11111111-2222-3333-4444-555555555555\n"]);

test('unactivated and time passes cannot use the limited entry command', function (string $kind) {
    $membership = redemptionFixture($this->employee, $kind === 'time' ? null : 8, $kind !== 'pending');
    assertRedemptionInvalid($this->employee, $membership);
})->with(['pending', 'time']);

test('non expiring entry passes remain usable years after activation', function () {
    $membership = redemptionFixture($this->employee);
    $this->travel(20)->years();
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    expect($membership->fresh()->expires_on)->toBeNull()->and($membership->fresh()->entries_remaining)->toBe(7);
});

test('legacy dated entry periods respect inclusive expiry in Warsaw and retain history', function () {
    $membership = redemptionFixture($this->employee);
    // Preserve a legacy purchased period; new entry activation never adds this expiry.
    DB::table('memberships')->where('id', $membership->id)->update(['expires_on' => '2027-02-01']);
    $this->travelTo(CarbonImmutable::parse('2027-02-01T22:59:00Z'));
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    $this->travelTo(CarbonImmutable::parse('2027-02-01T23:00:00Z'));
    assertRedemptionInvalid($this->employee, $membership);
    expect(app(GetMembershipEntryHistory::class)->handle($this->employee, $membership->id)->total())->toBe(1);
});

test('future start dates cannot authorize early use', function () {
    $membership = redemptionFixture($this->employee);
    $this->travelTo(CarbonImmutable::parse('2027-01-29T12:00:00Z'));
    assertRedemptionInvalid($this->employee, $membership);
});

test('current cancelled unpaid and failed orders cannot use a pass', function (string $status) {
    $membership = redemptionFixture($this->employee);
    $membership->order->forceFill(['status' => $status])->save();
    assertRedemptionInvalid($this->employee, $membership, 'payment');
})->with(['pending', 'failed', 'cancelled']);

test('payment without current verified confirmation cannot authorize a use', function () {
    $membership = redemptionFixture($this->employee);
    Payment::sole()->forceFill(['status' => 'failed'])->save();
    assertRedemptionInvalid($this->employee, $membership, 'payment');
});

test('current catalog edits never replace the purchased allowance', function () {
    $membership = redemptionFixture($this->employee);
    $membership->order->plan->forceFill(['included_entries' => 4, 'is_active' => false])->save();
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    expect($membership->fresh()->entries_granted)->toBe(8)->and($membership->fresh()->entries_remaining)->toBe(7);
});

test('unauthorized stale suspended deleted and unsaved actors cannot use or read history', function (string $role) {
    $membership = redemptionFixture($this->employee);
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
    expect(fn () => app(RedeemMembershipEntry::class)->handle($actor, $membership->id, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(fn () => app(GetMembershipEntryHistory::class)->handle($actor, $membership->id))->toThrow(AuthorizationException::class);
    expect($membership->fresh()->entries_remaining)->toBe(8)->and(MembershipEntry::count())->toBe(0);
})->with(['customer', 'unverified', 'suspended', 'deleted', 'unsaved']);

test('audit failure rolls back balance and ledger and permits safe retry with the same UUID', function () {
    $membership = redemptionFixture($this->employee);
    $uuid = (string) Str::uuid();
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, $uuid))->toThrow(RuntimeException::class);
    expect($membership->fresh()->entries_remaining)->toBe(8)->and(MembershipEntry::count())->toBe(0);
    $this->app->forgetInstance(RecordAudit::class);
    $this->app->forgetInstance(RedeemMembershipEntry::class);
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, $uuid);
    expect($membership->fresh()->entries_remaining)->toBe(7)->and(MembershipEntry::count())->toBe(1);
});

test('ledger insert failure leaves the balance and audit unchanged', function () {
    $membership = redemptionFixture($this->employee);
    MembershipEntry::created(fn () => throw new RuntimeException('Test ledger failure'));
    try {
        expect(fn () => app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid()))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.created: '.MembershipEntry::class);
    }
    expect($membership->fresh()->entries_remaining)->toBe(8)->and(MembershipEntry::count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.entry_used')->count())->toBe(0);
});

test('entry history is paginated deterministic and retained when a pass is no longer usable', function () {
    $membership = redemptionFixture($this->employee, 25);
    for ($index = 0; $index < 21; $index++) {
        app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    }
    $membership->order->forceFill(['status' => 'cancelled'])->save();
    $history = app(GetMembershipEntryHistory::class);
    $first = $history->handle($this->employee, $membership->id);
    $second = $history->handle(User::factory()->admin()->create(), $membership->id, 2);
    expect($first->total())->toBe(21)->and($first->count())->toBe(20)->and($second->count())->toBe(1)
        ->and($first->first()->id)->toBe(MembershipEntry::max('id'))->and($first->first()->recordedBy->name)->toBe($this->employee->name);
    expect($first->first()->recordedBy->getAttributes())->toHaveKeys(['id', 'name'])->not->toHaveKeys(['email', 'phone', 'password']);
    expect($first->pluck('id')->intersect($second->pluck('id'))->isEmpty())->toBeTrue();
    expect(fn () => $history->handle($this->employee, $membership->id, 0))->toThrow(ValidationException::class);
});

test('entry ledger remains immutable and cannot be deleted', function () {
    $membership = redemptionFixture($this->employee);
    $entry = app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    expect(fn () => $entry->forceFill(['used_at' => now()->addDay()])->save())->toThrow(LogicException::class);
    expect(fn () => $entry->fresh()->delete())->toThrow(LogicException::class);
});

test('admin audit shows the balance change and only valid numeric summaries are accepted', function () {
    $membership = redemptionFixture($this->employee);
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action', 'Odnotowano wejście z karnetu')->where('entries.data.0.changes.0.before', '8')->where('entries.data.0.changes.0.after', '7'));
    foreach ([-1, 1.5, 'private@example.test', true, 4294967296] as $value) {
        expect(app(AuditPayload::class)->values(['entries_remaining' => $value]))->toBe([]);
    }
});

test('balance save failure also rolls back the ledger row inserted first', function () {
    $membership = redemptionFixture($this->employee);
    Membership::updating(fn () => throw new RuntimeException('Test balance save failure'));
    try {
        expect(fn () => app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid()))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.updating: '.Membership::class);
    }
    expect($membership->fresh()->entries_remaining)->toBe(8)->and(MembershipEntry::count())->toBe(0)
        ->and(AuditLog::where('action', 'membership.entry_used')->count())->toBe(0);
});

test('membership timestamp migration preserves data and refuses a lossy 2038 rollback', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL TIMESTAMP range regression.');
    }
    // Inspect the installed migration without running DDL inside RefreshDatabase's transaction.
    $columns = DB::select("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'memberships' AND COLUMN_NAME IN ('created_at', 'updated_at')");
    expect(array_unique(array_map(fn ($column) => $column->DATA_TYPE, $columns)))->toBe(['datetime']);
    $membership = redemptionFixture($this->employee);
    $before = $membership->refresh()->getAttributes();
    $this->travel(20)->years();
    app(RedeemMembershipEntry::class)->handle($this->employee, $membership->id, (string) Str::uuid());
    $migration = require database_path('migrations/2026_11_23_030000_use_datetime_for_membership_timestamps.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'losing membership history');
    expect($membership->fresh()->getRawOriginal('created_at'))->toBe($before['created_at'])
        ->and($membership->fresh()->updated_at->year)->toBe(2047)->and($membership->fresh()->entries_remaining)->toBe(7);
});
