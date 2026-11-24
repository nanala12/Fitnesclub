<?php

use App\Actions\Documents\GenerateMembershipPdf;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\IssuePaidMembership;
use App\Actions\Memberships\RenewPaidMembership;
use App\Exceptions\MembershipPdfException;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Services\Documents\MembershipPdfData;
use App\Services\Documents\MembershipPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24T12:00:00Z'));
    Storage::fake('membership_documents');
    Storage::fake('public');
    Mail::fake();
    Http::preventStrayRequests();
    $this->staff = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->entries(8)->create(['price_grosz' => 12050]);
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => $this->staff->id])->save();
    $this->order = Order::factory()->create(['membership_plan_id' => $this->plan->id, 'accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now(),
        'holder_first_name' => 'Żaneta', 'holder_last_name' => 'Łącka', 'purchaser_first_name' => 'Kupujący', 'recipient_email' => 'holder@example.test']);
    pdfConfirmFixture($this->order);
    $this->member = app(IssuePaidMembership::class)->handle($this->order->id, null);
    $this->delivery = DocumentDelivery::sole();
});

afterEach(function () {
    // Dispose only isolated fixtures before history-preserving migration rollback.
    DB::table('orders')->update(['checkout_request_id' => null, 'renewal_source_membership_id' => null]);
    DB::table('document_deliveries')->delete();
    DB::table('membership_entries')->delete();
    DB::table('membership_adjustments')->delete();
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
    DB::table('payments')->delete();
});

function pdfConfirmFixture(Order $order): void
{
    $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    Payment::factory()->create(['order_id' => $order->id, 'status' => 'confirmed', 'confirmed_at' => now(),
        'provider' => $order->sales_channel->value === 'online' ? 'przelewy24' : 'reception', 'method' => $order->payment_method,
        'confirmed_by_user_id' => $order->created_by_user_id]);
}

function pdfHtml(Membership $member): string
{
    return view('documents.membership', ['document' => MembershipPdfData::fromMembership($member->fresh(), $member->order()->firstOrFail(), 'ActiveFit')])->render();
}

test('pending PDF includes Unicode holder purchased terms and activation message without contact or invented dates', function () {
    $html = pdfHtml($this->member);
    expect($html)->toContain('Żaneta Łącka', 'ActiveFit', '120,50 zł', 'Zakupiony pakiet wejść', 'Ważność rozpocznie się przy pierwszej wizycie w klubie')
        ->not->toContain('buyer@example.test', 'holder@example.test', '+48600111222', '+48600333444', 'Kupujący', 'Ważny do', 'Data rozpoczęcia', 'Liczba miesięcy');
    $before = [$this->order->fresh()->getAttributes(), $this->member->fresh()->getAttributes(), Payment::sole()->getAttributes()];
    $path = app(GenerateMembershipPdf::class)->handle($this->delivery->id);
    $bytes = Storage::disk('membership_documents')->get($path);
    expect($bytes)->toStartWith('%PDF-')->and(substr($bytes, -32))->toContain('%%EOF')->and(strlen($bytes))->toBeGreaterThan(1000)
        ->and(Storage::disk('membership_documents')->getVisibility($path))->toBe('private')
        ->and($this->delivery->fresh()->storage_path)->toBe($path)->and($this->delivery->fresh()->status->value)->toBe('pending')
        ->and([$this->order->fresh()->getAttributes(), $this->member->fresh()->getAttributes(), Payment::sole()->getAttributes()])->toBe($before);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('repeated generation reuses one existing private artifact without rendering or changing payment', function () {
    $renderer = Mockery::mock(MembershipPdfRenderer::class)->makePartial();
    $renderer->shouldReceive('render')->once()->passthru();
    $this->app->instance(MembershipPdfRenderer::class, $renderer);
    $action = app(GenerateMembershipPdf::class);
    $path = $action->handle($this->delivery->id);
    expect($action->handle($this->delivery->id))->toBe($path)->and(Storage::disk('membership_documents')->allFiles())->toBe([$path])
        ->and(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1);
});

test('purchased data survives catalog and profile edits and ignores arbitrary snapshot notes', function () {
    $this->plan->forceFill(['name' => 'Nowy plan', 'price_grosz' => 99999, 'included_entries' => 99])->save();
    $html = pdfHtml($this->member);
    expect($html)->toContain('120,50 zł', 'Żaneta Łącka')->not->toContain('Nowy plan', '999,99');
    $data = MembershipPdfData::fromMembership($this->member, $this->order->fresh(), 'Klub <script>alert(1)</script>');
    $html = view('documents.membership', ['document' => $data])->render();
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>alert(1)</script>');
});

test('activated entry pass shows its start without month limit or expiry and keeps the bought allowance', function () {
    $member = app(ActivateMembership::class)->handle($this->staff, $this->member->id);
    $html = pdfHtml($member);
    expect($html)->toContain('24.11.2026', 'Bez ograniczenia czasowego')->not->toContain('Ważny do', 'Liczba miesięcy', 'Ważność rozpocznie');
    $path = app(GenerateMembershipPdf::class)->handle($this->delivery->id);
    expect(Storage::disk('membership_documents')->exists($path))->toBeTrue()->and($member->fresh()->entries_remaining)->toBe(8);
});

test('time pass uses stored real dates and never derives its start from payment time', function () {
    $plan = MembershipPlan::factory()->create(['duration_months' => 1]);
    $order = Order::factory()->create(['membership_plan_id' => $plan->id, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    pdfConfirmFixture($order);
    $member = app(IssuePaidMembership::class)->handle($order->id, null);
    expect(pdfHtml($member))->not->toContain('Data rozpoczęcia', 'Ważny do');
    $this->travelTo(CarbonImmutable::parse('2027-01-31T12:00:00Z'));
    $member = app(ActivateMembership::class)->handle($this->staff, $member->id);
    expect(pdfHtml($member))->toContain('31.01.2027', '28.02.2027', 'Ważny do (włącznie)');
});

test('paid future renewal PDF shows its actual scheduled period now', function () {
    $plan = MembershipPlan::factory()->create(['duration_months' => 1]);
    $sourceOrder = Order::factory()->create(['membership_plan_id' => $plan->id, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    pdfConfirmFixture($sourceOrder);
    $source = app(IssuePaidMembership::class)->handle($sourceOrder->id, null);
    $source = app(ActivateMembership::class)->handle($this->staff, $source->id);
    $order = Order::factory()->create(['membership_plan_id' => $plan->id, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    pdfConfirmFixture($order);
    $renewal = app(RenewPaidMembership::class)->handle($this->staff, $order->id, $source->id);
    $html = pdfHtml($renewal);
    expect($html)->toContain($source->expires_on->addDay()->format('d.m.Y'), $renewal->expires_on->format('d.m.Y'))
        ->not->toContain('Ważność rozpocznie się przy pierwszej wizycie');
    $delivery = DocumentDelivery::where('membership_id', $renewal->id)->sole();
    app(GenerateMembershipPdf::class)->handle($delivery->id);
    expect($delivery->fresh()->storage_path)->not->toBeNull()->and($renewal->activated_at)->toBeNull();
});

test('entry rollover PDF separates purchased allowance from total transferred grant', function () {
    app(ActivateMembership::class)->handle($this->staff, $this->member->id);
    $order = Order::factory()->create(['membership_plan_id' => $this->plan->id, 'holder_first_name' => 'Żaneta', 'holder_last_name' => 'Łącka',
        'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    pdfConfirmFixture($order);
    $renewal = app(RenewPaidMembership::class)->handle($this->staff, $order->id, $this->member->id);
    $data = MembershipPdfData::fromMembership($renewal, $order->fresh(), 'ActiveFit');
    expect($data->includedEntries)->toBe(8)->and($data->grantedEntries)->toBe(16)->and($data->expiresOn)->toBeNull();
});

test('unpaid inconsistent or unconfirmed payment cannot generate a PDF', function (string $table, array $changes) {
    DB::table($table)->where('id', $table === 'orders' ? $this->order->id : Payment::sole()->id)->update($changes);
    expect(fn () => app(GenerateMembershipPdf::class)->handle($this->delivery->id))->toThrow(MembershipPdfException::class);
    expect(Storage::disk('membership_documents')->allFiles())->toBe([])->and($this->delivery->fresh()->storage_path)->toBeNull();
})->with([
    ['orders', ['status' => 'pending', 'paid_at' => null]], ['orders', ['status' => 'failed', 'paid_at' => null]],
    ['payments', ['status' => 'pending', 'confirmed_at' => null]], ['payments', ['status' => 'failed', 'confirmed_at' => null]],
    ['payments', ['amount_grosz' => 1]], ['orders', ['status' => 'pending', 'paid_at' => null, 'accepted_regulation_version_id' => null, 'regulation_accepted_at' => null]],
]);

test('inconsistent outbox recipient or untrusted storage path is rejected', function (array $changes) {
    DB::table('document_deliveries')->where('id', $this->delivery->id)->update($changes);
    expect(fn () => app(GenerateMembershipPdf::class)->handle($this->delivery->id))->toThrow(MembershipPdfException::class);
    expect(Storage::disk('membership_documents')->allFiles())->toBe([]);
})->with([[['recipient_email' => 'other@example.test']], [['storage_path' => '../../.env']], [['kind' => 'other']]]);

test('renderer and database failures preserve paid purchase and permit safe retry', function (bool $databaseFailure) {
    if ($databaseFailure) {
        Event::listen('eloquent.updating: '.DocumentDelivery::class, fn () => throw new RuntimeException('holder@example.test'));
    } else {
        $renderer = Mockery::mock(MembershipPdfRenderer::class);
        $renderer->shouldReceive('render')->once()->andThrow(new RuntimeException('holder@example.test'));
        $this->app->instance(MembershipPdfRenderer::class, $renderer);
    }
    try {
        app(GenerateMembershipPdf::class)->handle($this->delivery->id);
        $this->fail('Expected safe PDF failure.');
    } catch (MembershipPdfException $exception) {
        expect($exception->getMessage())->not->toContain('holder@example.test')->and($exception->getPrevious())->toBeNull();
    }
    expect($this->order->fresh()->status->value)->toBe('paid')->and($this->delivery->fresh()->storage_path)->toBeNull()
        ->and(Storage::disk('membership_documents')->allFiles())->toBe([])->and(Membership::count())->toBe(1);
    Event::forget('eloquent.updating: '.DocumentDelivery::class);
    $this->app->forgetInstance(MembershipPdfRenderer::class);
    expect(app(GenerateMembershipPdf::class)->handle($this->delivery->id))->toStartWith('membership-pdfs/');
})->with([false, true]);

test('failed storage cannot publish a path or mark email sent', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::set('membership_documents', $disk);
    expect(fn () => app(GenerateMembershipPdf::class)->handle($this->delivery->id))->toThrow(MembershipPdfException::class)
        ->and($this->delivery->fresh()->storage_path)->toBeNull()->and($this->delivery->fresh()->sent_at)->toBeNull();
});

test('outer payment transaction cannot generate private files before commit', function () {
    expect(fn () => DB::transaction(fn () => app(GenerateMembershipPdf::class)->handle($this->delivery->id)))->toThrow(LogicException::class);
    expect(Storage::disk('membership_documents')->allFiles())->toBe([]);
});

test('missing file can be regenerated without changing delivery identity or sending email', function () {
    $action = app(GenerateMembershipPdf::class);
    $path = $action->handle($this->delivery->id);
    Storage::disk('membership_documents')->delete($path);
    $replacement = $action->handle($this->delivery->id);
    expect($replacement)->not->toBe($path)->and(DocumentDelivery::count())->toBe(1)->and(Membership::count())->toBe(1);
    Mail::assertNothingSent();
});

test('account holder purchaser and verified staff can download while unrelated customers cannot', function (string $role) {
    $user = match ($role) {
        'holder', 'purchaser', 'unrelated' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
        default => User::factory()->employee()->create(),
    };
    if ($role === 'holder') {
        DB::table('memberships')->where('id', $this->member->id)->update(['holder_user_id' => $user->id]);
        DB::table('orders')->where('id', $this->order->id)->update(['holder_user_id' => $user->id]);
    } elseif ($role === 'purchaser') {
        DB::table('orders')->where('id', $this->order->id)->update(['purchaser_user_id' => $user->id]);
    }
    $response = $this->actingAs($user)->get(route('memberships.pdf', $this->member->id));
    if ($role === 'unrelated') {
        $response->assertNotFound();
        expect($this->delivery->fresh()->storage_path)->toBeNull();
    } else {
        $response->assertOk()->assertDownload('karnet-activefit.pdf')->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        expect($response->streamedContent())->toStartWith('%PDF-');
    }
})->with(['holder', 'purchaser', 'employee', 'admin', 'unrelated']);

test('guest checkout PDF requires the original session and is not public storage', function () {
    $id = (string) Str::uuid();
    DB::table('orders')->where('id', $this->order->id)->update(['checkout_request_id' => $id]);
    $url = route('checkout.pdf', $this->order->public_reference);
    $this->get($url)->assertNotFound();
    $this->withSession(['checkout_requests' => [$id => ['issued_at' => now()->timestamp, 'user_id' => null]]])->get($url)->assertOk()->assertDownload();
    $path = $this->delivery->fresh()->storage_path;
    $this->get('/storage/'.$path)->assertForbidden();
    $this->get('/storage/app/membership-documents/'.$path)->assertForbidden();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['email' => 'holder@example.test']))->get($url)->assertNotFound();
});

test('suspended staff and unverified unrelated employee cannot download PDF', function (bool $suspended) {
    $staff = $suspended ? User::factory()->employee()->suspended()->create() : User::factory()->employee()->unverified()->create();
    $response = $this->actingAs($staff)->get(route('memberships.pdf', $this->member->id));
    $suspended ? $response->assertForbidden() : $response->assertNotFound();
    expect($this->delivery->fresh()->storage_path)->toBeNull();
})->with([false, true]);

test('generation command prepares pending outbox files without changing delivery status', function () {
    $this->artisan('documents:generate-membership-pdfs', ['--limit' => 0])->assertExitCode(2);
    $this->artisan('documents:generate-membership-pdfs', ['--delivery' => $this->delivery->id])->assertSuccessful();
    expect($this->delivery->fresh()->storage_path)->not->toBeNull()->and($this->delivery->fresh()->status->value)->toBe('pending');
    Mail::assertNothingSent();
});

test('cash and card reception purchases produce private PDFs without an account', function (string $method) {
    $order = Order::factory()->paidAtReception()->create(['payment_method' => $method]);
    pdfConfirmFixture($order);
    $member = app(IssuePaidMembership::class)->handle($order->id, null);
    $delivery = DocumentDelivery::where('membership_id', $member->id)->sole();
    $path = app(GenerateMembershipPdf::class)->handle($delivery->id);
    expect(Storage::disk('membership_documents')->get($path))->toStartWith('%PDF-')->and($member->holder_user_id)->toBeNull();
    Mail::assertNothingSent();
})->with(['cash', 'card']);

test('public default disk cannot change the private destination', function () {
    config(['filesystems.default' => 'public']);
    $path = app(GenerateMembershipPdf::class)->handle($this->delivery->id);
    expect(Storage::disk('membership_documents')->exists($path))->toBeTrue()->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('arbitrary snapshot notes and contacts never enter PDF content', function () {
    $snapshot = [...$this->order->plan_snapshot, 'terms' => ['note' => 'secret-administrative-note', 'email' => 'holder@example.test']];
    DB::table('orders')->where('id', $this->order->id)->update(['plan_snapshot' => json_encode($snapshot)]);
    DB::table('memberships')->where('id', $this->member->id)->update(['plan_snapshot' => json_encode($snapshot)]);
    expect(pdfHtml($this->member))->not->toContain('secret-administrative-note', 'holder@example.test');
});

test('a file path belonging to another delivery cannot be downloaded', function () {
    $path = app(GenerateMembershipPdf::class)->handle($this->delivery->id);
    $other = DocumentDelivery::factory()->create(['membership_id' => $this->member->id, 'recipient_email' => $this->order->recipient_email, 'storage_path' => $path]);
    expect(fn () => app(GenerateMembershipPdf::class)->handle($other->id))->toThrow(MembershipPdfException::class);
});
