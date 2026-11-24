<?php

namespace App\Console\Commands;

use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentVerificationException;
use App\Models\Payment;
use App\Services\Payments\Przelewy24Configuration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReconcilePrzelewy24Payments extends Command
{
    protected $signature = 'payments:reconcile-p24 {--payment= : Internal payment ID} {--limit=25 : Maximum payments, 1..100}';

    protected $description = 'Check pending P24 sessions without registering or charging a new transaction';

    public function handle(VerifyPrzelewy24Payment $verify): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $id = $this->option('payment');
        if ($limit === false || ($id !== null && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Podaj poprawne ID płatności i limit 1–100.');

            return self::INVALID;
        }
        try {
            Przelewy24Configuration::load();
        } catch (ValidationException) {
            $this->error('Konfiguracja Przelewy24 jest niedostępna.');

            return self::FAILURE;
        }
        $query = Payment::query()->where('provider', PaymentProvider::Przelewy24)->whereNotNull('provider_session_id')
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending, PaymentStatus::Unknown]);
        if ($id !== null) {
            $query->whereKey((int) $id);
        }
        // Oldest checked first so unresolved rows cannot starve the rest of the batch.
        $ids = $query->orderBy('verification_last_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        $errors = 0;
        foreach ($ids as $paymentId) {
            try {
                $membership = $verify->reconcile($paymentId);
                $this->line('Płatność #'.$paymentId.': '.($membership === null ? 'brak potwierdzenia opłaty' : 'opłata potwierdzona').'.');
            } catch (PaymentVerificationException $exception) {
                $errors++;
                Log::warning('P24 reconciliation not finalized.', ['payment_id' => $paymentId, 'reason' => $exception->failureCode]);
                $this->warn('Płatność #'.$paymentId.': wymaga ponowienia lub sprawdzenia.');
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
