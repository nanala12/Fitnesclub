<?php

namespace App\Console\Commands;

use App\Actions\Documents\DeliverMembershipDocument;
use App\Actions\Documents\DispatchDocumentDelivery;
use App\Enums\DeliveryStatus;
use App\Models\DocumentDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryDocumentDeliveries extends Command
{
    protected $signature = 'documents:retry-deliveries {--limit=100 : Maximum records per recovery phase, 1..100}';

    protected $description = 'Recover interrupted PDF delivery attempts and queue due bounded retries';

    public function handle(DeliverMembershipDocument $deliver, DispatchDocumentDelivery $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Podaj limit 1–100.');

            return self::INVALID;
        }
        $expired = DocumentDelivery::query()->where('status', DeliveryStatus::Processing)
            ->where('processing_started_at', '<=', now()->subSeconds(DocumentDelivery::LEASE_SECONDS))->orderBy('id')->limit($limit)->pluck('id');
        foreach ($expired as $id) {
            try {
                $deliver->recoverInterrupted($id);
            } catch (Throwable) {
                Log::warning('Membership delivery recovery requires review.', ['delivery_id' => $id]);
            }
        }
        $ids = DocumentDelivery::query()->where('kind', 'membership_pdf')->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Failed])
            ->where('needs_review', false)->where('attempts_count', '<', DocumentDelivery::MAX_ATTEMPTS)->where('scheduled_for', '<=', now())
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subSeconds(60)))
            ->orderBy('scheduled_for')->orderBy('id')->limit($limit)->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            $queued += (int) $dispatch->handle($id);
        }
        $this->info('Zlecono wysyłki PDF: '.$queued.'.');

        return self::SUCCESS;
    }
}
