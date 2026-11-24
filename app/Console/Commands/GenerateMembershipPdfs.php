<?php

namespace App\Console\Commands;

use App\Actions\Documents\GenerateMembershipPdf;
use App\Exceptions\MembershipPdfException;
use App\Models\DocumentDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateMembershipPdfs extends Command
{
    protected $signature = 'documents:generate-membership-pdfs {--delivery= : Internal delivery ID} {--limit=25 : Maximum documents, 1..100}';

    protected $description = 'Generate private PDFs for paid membership outbox requests without sending email';

    public function handle(GenerateMembershipPdf $generate): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $id = $this->option('delivery');
        if ($limit === false || ($id !== null && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Podaj poprawne ID zlecenia i limit 1–100.');

            return self::INVALID;
        }
        $query = DocumentDelivery::query()->where('kind', 'membership_pdf');
        if ($id === null) {
            $query->whereNull('storage_path')->where('scheduled_for', '<=', now());
        } else {
            $query->whereKey((int) $id);
        }
        $ids = $query->orderBy('id')->limit($limit)->pluck('id');
        $errors = 0;
        foreach ($ids as $deliveryId) {
            try {
                $generate->handle($deliveryId);
                $this->line('Przygotowano PDF zlecenia #'.$deliveryId.'.');
            } catch (MembershipPdfException $exception) {
                $errors++;
                Log::warning('Membership PDF generation requires retry.', ['delivery_id' => $deliveryId, 'reason' => $exception->failureCode]);
                $this->warn('Zlecenie #'.$deliveryId.' wymaga ponowienia lub sprawdzenia.');
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
