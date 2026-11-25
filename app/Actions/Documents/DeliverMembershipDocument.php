<?php

namespace App\Actions\Documents;

use App\Enums\DeliveryStatus;
use App\Exceptions\MembershipPdfException;
use App\Mail\MembershipPass;
use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class DeliverMembershipDocument
{
    public function __construct(private GenerateMembershipPdf $pdf, private DispatchDocumentDelivery $dispatch) {}

    public function handle(int $deliveryId, int $expectedAttempts): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Membership delivery requires committed data outside a transaction.');
        }
        $token = DB::transaction(function () use ($deliveryId, $expectedAttempts): ?string {
            $delivery = DocumentDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->kind !== 'membership_pdf' || $delivery->needs_review
                || ! in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)
                || $delivery->attempts_count !== $expectedAttempts || $delivery->attempts_count >= DocumentDelivery::MAX_ATTEMPTS
                || $delivery->scheduled_for->isFuture()) {
                return null;
            }
            $token = (string) Str::uuid();
            $delivery->forceFill(['status' => DeliveryStatus::Processing, 'attempts_count' => $delivery->attempts_count + 1,
                'processing_token' => $token, 'processing_started_at' => now(), 'queued_at' => null, 'last_error_code' => null])->save();

            return $token;
        }, 3);
        if ($token === null) {
            return;
        }
        $accepted = false;
        try {
            $path = $this->pdf->handle($deliveryId);
            $delivery = DocumentDelivery::query()->findOrFail($deliveryId);
            if ($delivery->status !== DeliveryStatus::Processing || $delivery->processing_token !== $token
                || $delivery->processing_started_at?->addSeconds(DocumentDelivery::LEASE_SECONDS)->isPast()) {
                return;
            }
            // SMTP happens after PDF/payment transactions commit. The job contains no personal data.
            Mail::mailer('membership')->to($delivery->recipient_email)->send(new MembershipPass($deliveryId, $path));
            $accepted = true;
            $this->finish($deliveryId, $token, true);
        } catch (Throwable $exception) {
            $code = $exception instanceof MembershipPdfException ? $exception->failureCode : ($accepted ? 'delivery_ack_failed' : 'mail_transport_failed');
            try {
                $this->finish($deliveryId, $token, false, $code, $accepted);
            } catch (Throwable) {
                Log::warning('Membership delivery result requires review.', ['delivery_id' => $deliveryId]);
            }
            Log::warning('Membership delivery attempt failed.', ['delivery_id' => $deliveryId, 'reason' => $code]);
        }
    }

    private function finish(int $deliveryId, string $token, bool $sent, ?string $code = null, bool $review = false): void
    {
        $retry = DB::transaction(function () use ($deliveryId, $token, $sent, $code, $review): bool {
            $delivery = DocumentDelivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== DeliveryStatus::Processing || $delivery->processing_token !== $token) {
                return false;
            }
            $this->attempt($delivery, $sent, $code);
            $attributes = ['status' => $sent ? DeliveryStatus::Sent : DeliveryStatus::Failed, 'processing_token' => null,
                'processing_started_at' => null, 'queued_at' => null, 'last_error_code' => $code, 'needs_review' => $review];
            $retry = ! $sent && ! $review && $delivery->attempts_count < DocumentDelivery::MAX_ATTEMPTS;
            if ($sent) {
                $attributes['sent_at'] = now();
            } elseif ($retry) {
                $attributes['scheduled_for'] = now()->addSeconds(DocumentDelivery::RETRY_DELAYS[$delivery->attempts_count - 1]);
            }
            $delivery->forceFill($attributes)->save();

            return $retry;
        }, 3);
        if ($retry) {
            $this->dispatch->handle($deliveryId);
        }
    }

    public function recoverInterrupted(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = DocumentDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->status !== DeliveryStatus::Processing || $delivery->processing_started_at === null
                || $delivery->processing_started_at->addSeconds(DocumentDelivery::LEASE_SECONDS)->isFuture()) {
                return;
            }
            if ($delivery->attempts_count > 0) {
                $this->attempt($delivery, false, 'delivery_interrupted');
            }
            $delivery->forceFill(['status' => DeliveryStatus::Failed, 'needs_review' => true, 'last_error_code' => 'delivery_interrupted',
                'processing_token' => null, 'processing_started_at' => null, 'queued_at' => null])->save();
        }, 3);
    }

    private function attempt(DocumentDelivery $delivery, bool $sent, ?string $code): void
    {
        $attempt = new DocumentDeliveryAttempt;
        $attempt->forceFill(['document_delivery_id' => $delivery->id, 'attempt_number' => $delivery->attempts_count,
            'status' => $sent ? 'sent' : 'failed', 'error_code' => $code, 'triggered_by_user_id' => $delivery->requested_by_user_id,
            'attempted_at' => $delivery->processing_started_at])->save();
    }
}
