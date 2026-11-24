<?php

namespace App\Actions\Documents;

use App\Actions\Memberships\LockMembershipOrder;
use App\Actions\Payments\RequireConfirmedOrderPayment;
use App\Exceptions\MembershipPdfException;
use App\Models\ClubSetting;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Services\Documents\MembershipPdfData;
use App\Services\Documents\MembershipPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class GenerateMembershipPdf
{
    public function __construct(private LockMembershipOrder $orders, private RequireConfirmedOrderPayment $payment, private MembershipPdfRenderer $renderer) {}

    /** Internal outbox operation after payment commit; HTTP callers must authorize access first. */
    public function handle(int $deliveryId): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('PDF generation requires a committed payment outside an outer transaction.');
        }
        $written = [];
        try {
            return DB::transaction(function () use ($deliveryId, &$written): string {
                $locator = DocumentDelivery::query()->findOrFail($deliveryId);
                $memberLocator = Membership::query()->findOrFail($locator->membership_id);
                $order = $this->orders->handle($memberLocator);
                $this->payment->handle($order);
                $membership = Membership::query()->whereKey($memberLocator->id)->lockForUpdate()->firstOrFail();
                $delivery = DocumentDelivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
                if ($delivery->membership_id !== $membership->id || $delivery->kind !== 'membership_pdf' || $delivery->recipient_email !== $order->recipient_email) {
                    throw new MembershipPdfException('invalid_delivery_context');
                }
                $club = trim((string) (ClubSetting::query()->whereKey(1)->value('club_name') ?? config('app.name')));
                $data = MembershipPdfData::fromMembership($membership, $order, $club === '' ? 'ActiveFit' : $club);
                $disk = Storage::disk('membership_documents');
                if ($delivery->storage_path !== null) {
                    if (! preg_match('~^membership-pdfs/'.$delivery->id.'/[a-f0-9-]{36}\.pdf$~D', $delivery->storage_path)) {
                        throw new MembershipPdfException('invalid_document_path');
                    }
                    if ($disk->exists($delivery->storage_path)) {
                        return $delivery->storage_path;
                    }
                }
                // Local rendering/file write only; no remote resources, email or payment calls.
                $bytes = $this->renderer->render($data);
                if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -32), '%%EOF')) {
                    throw new MembershipPdfException('invalid_pdf_output');
                }
                $path = 'membership-pdfs/'.$delivery->id.'/'.Str::uuid().'.pdf';
                $written[] = $path;
                if (! $disk->put($path, $bytes, ['visibility' => 'private'])) {
                    throw new MembershipPdfException('pdf_storage_failed');
                }
                $delivery->forceFill(['storage_path' => $path])->save();

                return $path;
            }, 3);
        } catch (Throwable $exception) {
            throw new MembershipPdfException($exception instanceof MembershipPdfException ? $exception->failureCode : 'pdf_generation_failed');
        } finally {
            // Keep any file whose commit may have succeeded; remove only unreferenced attempts.
            foreach ($written as $path) {
                try {
                    if (! DocumentDelivery::query()->where('storage_path', $path)->exists()) {
                        Storage::disk('membership_documents')->delete($path);
                    }
                } catch (Throwable) {
                    Log::warning('Membership PDF cleanup requires review.', ['delivery_id' => $deliveryId]);
                }
            }
        }
    }
}
