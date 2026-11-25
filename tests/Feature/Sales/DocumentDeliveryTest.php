<?php

use App\Actions\Documents\DeliverMembershipDocument;
use App\Actions\Documents\DispatchDocumentDelivery;
use App\Actions\Documents\GenerateMembershipPdf;
use App\Actions\Documents\RequestDocumentResend;
use App\Actions\Memberships\IssuePaidMembership;
use App\Enums\DeliveryStatus;
use App\Exceptions\MembershipPdfException;
use App\Jobs\SendMembershipDocument;
use App\Mail\MembershipPass;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(now()->setMicrosecond(0));
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Mail::fake();
    Storage::fake('membership_documents');
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->order = Order::factory()->paidAtReception()->create(['recipient_email' => 'holder@example.test']);
    Payment::factory()->create(['order_id' => $this->order->id, 'status' => 'confirmed', 'confirmed_at' => now(),
        'provider' => 'reception', 'method' => 'cash', 'confirmed_by_user_id' => $this->order->created_by_user_id]);
    $this->member = app(IssuePaidMembership::class)->handle($this->order->id, null);
    $this->delivery = DocumentDelivery::sole();
});

afterEach(function () {
    DB::table('document_delivery_attempts')->delete();
    foreach (DB::table('document_deliveries')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('document_deliveries')->where('id', $id)->delete();
    }
});

test('issuance dispatches one database job after commit with no private data in its payload', function () {
    Queue::assertPushed(SendMembershipDocument::class, 1);
    Queue::assertPushed(SendMembershipDocument::class, fn ($job) => $job->deliveryId === $this->delivery->id
        && $job->expectedAttempts === 0 && $job->connection === 'database' && $job->afterCommit === true);
    $job = new SendMembershipDocument($this->delivery->id, 0);
    expect(serialize($job))->not->toContain('holder@example.test', 'buyer@example.test', 'recipient_email', 'storage_path');
    app(IssuePaidMembership::class)->handle($this->order->id, null);
    Queue::assertPushed(SendMembershipDocument::class, 1);
    Mail::assertNothingSent();
});

test('outer rollback removes issuance and never dispatches its PDF email', function () {
    $other = Order::factory()->paidAtReception()->create();
    Payment::factory()->create(['order_id' => $other->id, 'status' => 'confirmed', 'confirmed_at' => now(),
        'provider' => 'reception', 'method' => 'cash', 'confirmed_by_user_id' => $other->created_by_user_id]);
    expect(fn () => DB::transaction(function () use ($other) {
        app(IssuePaidMembership::class)->handle($other->id, null);
        Queue::assertPushed(SendMembershipDocument::class, 1);
        throw new RuntimeException('rollback fixture');
    }))->toThrow(RuntimeException::class);
    expect(DocumentDelivery::count())->toBe(1)->and(Membership::count())->toBe(1);
    Queue::assertPushed(SendMembershipDocument::class, 1);
});

test('transport failure never changes the purchase pass or payment and retries with bounded backoff', function () {
    Mail::shouldReceive('mailer')->with('membership')->andReturnSelf();
    Mail::shouldReceive('to')->with('holder@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->times(5)->andThrow(new RuntimeException('secret smtp password holder@example.test'));
    $before = [$this->order->fresh()->getAttributes(), $this->member->fresh()->getAttributes(), Payment::sole()->getAttributes()];
    $action = app(DeliverMembershipDocument::class);
    foreach ([0, 1, 2, 3, 4] as $expected) {
        $action->handle($this->delivery->id, $expected);
        $delivery = $this->delivery->fresh();
        expect($delivery->status)->toBe(DeliveryStatus::Failed)->and($delivery->attempts_count)->toBe($expected + 1)
            ->and($delivery->last_error_code)->toBe('mail_transport_failed')->and($delivery->needs_review)->toBeFalse();
        if ($expected < 4) {
            expect($delivery->scheduled_for->timestamp - now()->timestamp)->toBe(DocumentDelivery::RETRY_DELAYS[$expected]);
            $this->travelTo($delivery->scheduled_for);
        }
    }
    $action->handle($this->delivery->id, 5);
    expect(DocumentDeliveryAttempt::count())->toBe(5)->and(DocumentDeliveryAttempt::pluck('attempt_number')->all())->toBe([1, 2, 3, 4, 5])
        ->and([$this->order->fresh()->getAttributes(), $this->member->fresh()->getAttributes(), Payment::sole()->getAttributes()])->toBe($before);
});

test('successful send records immutable history and duplicate jobs never send twice', function () {
    $action = app(DeliverMembershipDocument::class);
    $action->handle($this->delivery->id, 0);
    $sentAt = $this->delivery->fresh()->sent_at;
    $action->handle($this->delivery->id, 0);
    $action->handle($this->delivery->id, 1);
    Mail::assertSent(MembershipPass::class, 1);
    $path = $this->delivery->fresh()->storage_path;
    Mail::assertSent(MembershipPass::class, function ($mail) use ($path) {
        $mail->assertHasAttachedData(Storage::disk('membership_documents')->get($path), 'karnet-activefit.pdf', ['mime' => 'application/pdf']);

        return $mail->hasTo('holder@example.test') && ! $mail->hasTo('buyer@example.test');
    });
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Sent)->and($this->delivery->fresh()->sent_at->equalTo($sentAt))->toBeTrue()
        ->and(DocumentDeliveryAttempt::sole()->status)->toBe('sent')->and(DocumentDeliveryAttempt::sole()->attempt_number)->toBe(1);
    expect(fn () => DocumentDeliveryAttempt::sole()->forceFill(['status' => 'failed'])->save())->toThrow(LogicException::class);
});

test('mail is sent outside database transactions and nested duplicate dispatch is harmless', function () {
    $called = 0;
    Mail::shouldReceive('mailer')->with('membership')->andReturnSelf();
    Mail::shouldReceive('to')->with('holder@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andReturnUsing(function () use (&$called) {
        $called++;
        expect(DB::transactionLevel())->toBe(0);
        app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    });
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    expect($called)->toBe(1)->and(DocumentDeliveryAttempt::count())->toBe(1);
});

test('stale jobs and not yet due retries cannot consume another attempt', function () {
    DB::table('document_deliveries')->where('id', $this->delivery->id)->update(['attempts_count' => 1, 'status' => 'failed', 'scheduled_for' => now()->addMinutes(5)]);
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 1);
    expect($this->delivery->fresh()->attempts_count)->toBe(1)->and(DocumentDeliveryAttempt::count())->toBe(0);
    Mail::assertNothingSent();
});

test('PDF failure is retained for retry without invoking the mail transport', function () {
    $pdf = Mockery::mock(GenerateMembershipPdf::class);
    $pdf->shouldReceive('handle')->once()->andThrow(new MembershipPdfException('pdf_storage_failed'));
    $this->app->instance(GenerateMembershipPdf::class, $pdf);
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)->and($this->delivery->fresh()->last_error_code)->toBe('pdf_storage_failed')
        ->and(DocumentDeliveryAttempt::sole()->error_code)->toBe('pdf_storage_failed')->and($this->order->fresh()->status->value)->toBe('paid');
    Mail::assertNothingSent();
});

test('successful SMTP with failed acknowledgement requires review instead of automatic retry', function () {
    Event::listen('eloquent.updating: '.DocumentDelivery::class, function (DocumentDelivery $delivery) {
        if ($delivery->status === DeliveryStatus::Sent) {
            throw new RuntimeException('injected acknowledgement failure');
        }
    });
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    expect($this->delivery->fresh()->needs_review)->toBeTrue()->and($this->delivery->fresh()->last_error_code)->toBe('delivery_ack_failed')
        ->and($this->delivery->fresh()->sent_at)->toBeNull()->and(DocumentDeliveryAttempt::sole()->status)->toBe('failed');
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 1);
    Mail::assertSent(MembershipPass::class, 1);
    Event::forget('eloquent.updating: '.DocumentDelivery::class);
});

test('interrupted processing is recorded once and never automatically resent', function () {
    DB::table('document_deliveries')->where('id', $this->delivery->id)->update(['status' => 'processing', 'attempts_count' => 1,
        'processing_token' => Str::uuid(), 'processing_started_at' => now()->subSeconds(181)]);
    $action = app(DeliverMembershipDocument::class);
    $action->recoverInterrupted($this->delivery->id);
    $action->recoverInterrupted($this->delivery->id);
    $action->handle($this->delivery->id, 1);
    expect($this->delivery->fresh()->needs_review)->toBeTrue()->and(DocumentDeliveryAttempt::count())->toBe(1)
        ->and(DocumentDeliveryAttempt::sole()->error_code)->toBe('delivery_interrupted');
    Mail::assertNothingSent();
});

test('dispatch failure leaves a durable retry and no raw broker data in logs', function () {
    DB::table('document_deliveries')->where('id', $this->delivery->id)->update(['queued_at' => null]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('holder@example.test broker secret'));
    Log::spy();
    expect(app(DispatchDocumentDelivery::class)->handle($this->delivery->id))->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Membership delivery dispatch requires retry.', ['delivery_id' => $this->delivery->id])->once();
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Pending)->and($this->delivery->fresh()->attempts_count)->toBe(0);
});

test('recovery command queues due rows and debounces repeated scans', function () {
    $this->travel(61)->seconds();
    $this->artisan('documents:retry-deliveries', ['--limit' => 0])->assertExitCode(2);
    $this->artisan('documents:retry-deliveries')->assertSuccessful();
    Queue::assertPushed(SendMembershipDocument::class, 2);
    $this->artisan('documents:retry-deliveries')->assertSuccessful();
    Queue::assertPushed(SendMembershipDocument::class, 2);
});

test('admin resend creates a separate auditable request preserving previous success and recipient', function () {
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    $sentAt = $this->delivery->fresh()->sent_at;
    $requestId = (string) Str::uuid();
    $action = app(RequestDocumentResend::class);
    $resend = $action->handle($this->admin, $this->delivery->id, $requestId);
    expect($action->handle($this->admin, $this->delivery->id, $requestId)->id)->toBe($resend->id)
        ->and($resend->root_delivery_id)->toBe($this->delivery->id)->and($resend->requested_by_user_id)->toBe($this->admin->id)
        ->and($resend->recipient_email)->toBe('holder@example.test')->and($this->delivery->fresh()->sent_at->equalTo($sentAt))->toBeTrue()
        ->and(DocumentDelivery::count())->toBe(2)->and(Membership::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and(AuditLog::where('action', 'document.resend_requested')->count())->toBe(1);
    app(DeliverMembershipDocument::class)->handle($resend->id, 0);
    Mail::assertSent(MembershipPass::class, 2);
    expect($resend->fresh()->status)->toBe(DeliveryStatus::Sent)
        ->and(DocumentDeliveryAttempt::where('document_delivery_id', $resend->id)->sole()->triggered_by_user_id)->toBe($this->admin->id);
});

test('another resend cannot overlap pending processing or a scheduled automatic retry', function () {
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    app(RequestDocumentResend::class)->handle($this->admin, $this->delivery->id, (string) Str::uuid());
    expect(fn () => app(RequestDocumentResend::class)->handle($this->admin, $this->delivery->id, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(DocumentDelivery::count())->toBe(2);
});

test('employees customers suspended and unverified admins cannot request a resend', function (string $role) {
    $actor = match ($role) {
        'employee' => User::factory()->employee()->create(), 'customer' => User::factory()->create(),
        'suspended' => User::factory()->admin()->suspended()->create(), default => User::factory()->admin()->unverified()->create(),
    };
    expect(fn () => app(RequestDocumentResend::class)->handle($actor, $this->delivery->id, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(DocumentDelivery::count())->toBe(1);
})->with(['employee', 'customer', 'suspended', 'unverified']);

test('admin page masks contacts and resend HTTP requires confirmation and rejects recipient overrides', function () {
    app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
    $this->actingAs($this->admin)->get(route('admin.deliveries.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/deliveries/Index')->has('deliveries.data', 1)->where('deliveries.data.0.recipientLabel', 'h***@example.test')
        ->where('deliveries.data.0.canResend', true)->missing('deliveries.data.0.recipient_email')->missing('deliveries.data.0.storage_path'));
    $this->post(route('admin.deliveries.resend', $this->delivery->id), ['request_id' => (string) Str::uuid()])->assertSessionHasErrors('confirm_resend');
    $this->post(route('admin.deliveries.resend', $this->delivery->id), ['request_id' => (string) Str::uuid(), 'confirm_resend' => true, 'recipient_email' => 'other@example.test'])->assertSessionHasErrors('recipient_email');
    $this->post(route('admin.deliveries.resend', $this->delivery->id), ['request_id' => (string) Str::uuid(), 'confirm_resend' => true])->assertRedirect(route('admin.deliveries.index'))->assertSessionHasNoErrors();
    expect(DocumentDelivery::count())->toBe(2);
});

test('outer transaction cannot send mail even on an already paid purchase', function () {
    expect(fn () => DB::transaction(fn () => app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0)))->toThrow(LogicException::class);
    Mail::assertNothingSent();
});

test('real database queue worker delivers the committed request without embedding contacts in the job', function () {
    Queue::swap($this->realQueue);
    DB::table('document_deliveries')->where('id', $this->delivery->id)->update(['queued_at' => null]);
    app(DispatchDocumentDelivery::class)->handle($this->delivery->id);
    expect(DB::table('jobs')->count())->toBe(1)->and(DB::table('jobs')->sole()->payload)->not->toContain('holder@example.test', 'storage_path', 'recipient_email');
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0)->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    Mail::assertSent(MembershipPass::class, 1);
});

test('actual MIME mail uses the private PDF attachment without leaking paths or contact data into its body', function () {
    $fake = Mail::getFacadeRoot();
    $manager = new MailManager($this->app);
    Mail::swap($manager);
    config(['mail.default' => 'log', 'mail.mailers.membership.transport' => 'array']);
    try {
        app(DeliverMembershipDocument::class)->handle($this->delivery->id, 0);
        $messages = $manager->mailer('membership')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1);
        $message = $messages->first()->getOriginalMessage();
        expect($message->getTo()[0]->getAddress())->toBe('holder@example.test')->and($message->getAttachments())->toHaveCount(1)
            ->and($message->getAttachments()[0]->getFilename())->toBe('karnet-activefit.pdf')
            ->and($message->getAttachments()[0]->getBody())->toStartWith('%PDF-')
            ->and($message->getHtmlBody())->not->toContain('holder@example.test', 'buyer@example.test', 'membership-pdfs/');
        expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
    } finally {
        Mail::swap($fake);
    }
});
