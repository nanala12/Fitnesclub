<?php

use App\Actions\Payments\ConfirmReceptionPayment;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00Z'));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('membership_documents');
    $this->customer = User::factory()->create();
    $this->staff = User::factory()->employee()->create();
});

afterEach(function () {
    DB::table('membership_entries')->delete();
    DB::table('orders')->update(['renewal_source_membership_id' => null]);
    DB::table('document_deliveries')->delete();
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

function customerPaidMember(array $orderAttributes = [], array $memberAttributes = []): Membership
{
    $order = Order::factory()->paidAtReception()->create(['status' => 'pending', 'paid_at' => null,
        'purchaser_user_id' => test()->customer->id, 'holder_user_id' => test()->customer->id,
        'holder_email' => 'holder@example.test', 'recipient_email' => 'holder@example.test', ...$orderAttributes]);
    $member = app(ConfirmReceptionPayment::class)->handle(test()->staff, $order->id);
    if ($memberAttributes !== []) {
        $member->forceFill($memberAttributes)->save();
    }

    return $member->fresh();
}

test('customer landing opens own membership panel and empty state remains private', function () {
    $this->actingAs($this->customer)->get(route('dashboard'))->assertRedirect(route('memberships.index'));
    $this->get(route('memberships.index'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page->component('memberships/Index')->has('memberships.data', 0));
});

test('explicit holder and purchaser links expose own and family passes once without guest or contact claiming', function () {
    $own = customerPaidMember();
    $family = customerPaidMember(['holder_user_id' => null, 'holder_first_name' => 'Jan']);
    $received = customerPaidMember(['purchaser_user_id' => User::factory()->create()->id]);
    $guest = customerPaidMember(['purchaser_user_id' => null, 'holder_user_id' => null, 'purchaser_email' => $this->customer->email,
        'holder_email' => $this->customer->email, 'recipient_email' => $this->customer->email, 'holder_phone' => $this->customer->phone]);
    $foreign = customerPaidMember(['purchaser_user_id' => User::factory()->create()->id, 'holder_user_id' => null]);
    $this->actingAs($this->customer)->get(route('memberships.index'))->assertInertia(fn (Assert $page) => $page->where('memberships.total', 3)
        ->where('memberships.data.0.id', $received->id)->where('memberships.data.1.id', $family->id)->where('memberships.data.1.forAnotherPerson', true)
        ->where('memberships.data.2.id', $own->id)->where('memberships.data.2.forAnotherPerson', false));
    foreach ([$guest, $foreign] as $member) {
        $this->get(route('memberships.show', $member->id))->assertNotFound();
    }
    expect($guest->fresh()->order->purchaser_user_id)->toBeNull();
});

test('pending member has no fabricated dates and PDF download cannot mutate activation or delivery status', function () {
    $member = customerPaidMember();
    $delivery = DocumentDelivery::sole();
    $this->actingAs($this->customer)->get(route('memberships.show', $member->id))->assertInertia(fn (Assert $page) => $page
        ->where('membership.state', 'pending_activation')->where('membership.startsOn', null)->where('membership.expiresOn', null)
        ->where('membership.pdfUrl', route('memberships.pdf', $member->id, absolute: false))->has('entries.data', 0)
        ->missing('membership.holder_email')->missing('membership.purchaser_email')->missing('membership.orderReference')->missing('membership.priceLabel'));
    $this->get(route('memberships.pdf', $member->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($member->fresh()->starts_on)->toBeNull()->and($member->fresh()->activated_at)->toBeNull()->and($delivery->fresh()->status->value)->toBe('pending');
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('stored lifecycle and historical entry actors stay visible without recalculating dates or balance', function (string $state, array $dates, bool $entries) {
    $plan = $entries ? MembershipPlan::factory()->entries(8)->create() : MembershipPlan::factory()->create();
    $member = customerPaidMember(['membership_plan_id' => $plan->id], ['activated_at' => now()->subDays(60), 'activated_by_user_id' => $this->staff->id, ...$dates]);
    if ($state === 'scheduled') {
        $source = customerPaidMember(['membership_plan_id' => $plan->id], ['activated_at' => now()->subDays(25), 'activated_by_user_id' => $this->staff->id,
            'starts_on' => '2026-11-01', 'expires_on' => '2026-11-27']);
        $member = customerPaidMember(['membership_plan_id' => $plan->id, 'renewal_source_membership_id' => $source->id]);
    }
    MembershipEntry::factory()->create(['membership_id' => $member->id, 'recorded_by_user_id' => $this->staff->id]);
    $before = $member->fresh()->getRawOriginal();
    $this->actingAs($this->customer)->get(route('memberships.show', $member->id))->assertInertia(fn (Assert $page) => $page
        ->where('membership.state', $state)->where('entries.total', 1)->where('entries.data.0.actor', $this->staff->name));
    expect($member->fresh()->getRawOriginal())->toBe($before);
})->with([
    'active' => ['active', ['starts_on' => '2026-11-25', 'expires_on' => '2026-12-24'], false],
    'scheduled' => ['scheduled', ['starts_on' => '2026-11-28', 'expires_on' => '2026-12-27'], false],
    'expired' => ['expired', ['starts_on' => '2026-09-26', 'expires_on' => '2026-10-26'], false],
    'exhausted' => ['exhausted', ['starts_on' => '2026-11-25', 'entries_remaining' => 0], true],
]);

test('unrelated users including staff have no personal-panel privilege bypass', function (string $role) {
    $member = customerPaidMember();
    $this->actingAs(User::factory()->create(['role' => $role]))->get(route('memberships.index'))->assertInertia(fn (Assert $page) => $page->has('memberships.data', 0));
    $this->get(route('memberships.show', $member->id))->assertNotFound();
})->with(['customer', 'employee', 'admin']);

test('authentication verification and suspension are enforced on list and detail', function () {
    $member = customerPaidMember();
    $this->get(route('memberships.index'))->assertRedirect(route('login'));
    $this->get(route('memberships.show', $member->id))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('memberships.index'))->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->suspended()->create())->get(route('memberships.show', $member->id))->assertForbidden();
});

test('member and entry history are paginated and invalid pages rejected', function () {
    for ($i = 0; $i < 13; $i++) {
        $member = customerPaidMember();
    }
    MembershipEntry::factory()->count(21)->create(['membership_id' => $member->id, 'recorded_by_user_id' => $this->staff->id]);
    $this->actingAs($this->customer)->get(route('memberships.index'))->assertInertia(fn (Assert $page) => $page->has('memberships.data', 12)->where('memberships.total', 13));
    $this->get(route('memberships.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('memberships.data', 1));
    $this->get(route('memberships.show', $member->id))->assertInertia(fn (Assert $page) => $page->has('entries.data', 20));
    $this->get(route('memberships.show', [$member->id, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->get(route('memberships.index', ['page' => 0]))->assertSessionHasErrors('page');
});
