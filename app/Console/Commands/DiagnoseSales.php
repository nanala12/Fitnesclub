<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\DocumentDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Przelewy24Configuration;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiagnoseSales extends Command
{
    protected $signature = 'sales:diagnose {--order= : Public order reference} {--limit=25 : Maximum rows per section, 1..100}';

    protected $description = 'Read-only payment and PDF diagnostics, without contacts, secrets or external requests';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $reference = $this->option('order');
        if ($limit === false || ($reference !== null && ! Str::isUlid($reference))) {
            $this->error('Podaj poprawny numer zamówienia i limit 1–100.');

            return self::INVALID;
        }
        $order = $reference === null ? null : Order::query()->where('public_reference', $reference)->first();
        if ($reference !== null && $order === null) {
            $this->error('Nie znaleziono zamówienia.');

            return self::FAILURE;
        }
        $settings = null;
        try {
            $settings = Przelewy24Configuration::load();
            $settings->returnUrlFor(str_repeat('0', 26));
        } catch (ValidationException) {
            $settings = null;
        }
        $payments = Payment::query()->where('provider', PaymentProvider::Przelewy24)
            ->when($order !== null, fn ($query) => $query->where('order_id', $order->id))
            ->when($order === null, fn ($query) => $query->where('status', '!=', PaymentStatus::Confirmed))
            ->orderBy('verification_last_attempt_at')->orderBy('id')->limit($limit)->get();
        $deliveries = DocumentDelivery::query()->where('kind', 'membership_pdf')
            ->when($order !== null, fn ($query) => $query->whereHas('membership', fn ($members) => $members->where('order_id', $order->id)))
            ->when($order === null, fn ($query) => $query->where('status', '!=', DeliveryStatus::Sent))
            ->orderBy('scheduled_for')->orderBy('id')->limit($limit)->get();
        $missing = Order::query()->where('status', OrderStatus::Paid)->doesntHave('membership')
            ->when($order !== null, fn ($query) => $query->whereKey($order->id))->orderBy('id')->limit($limit)->pluck('id');
        $this->line(json_encode([
            'checked_at_utc' => now('UTC')->toISOString(),
            'p24' => [
                'configuration_valid' => $settings !== null,
                'sandbox' => $settings?->environment === 'sandbox',
                'callback_path_matches' => $settings !== null && parse_url($settings->statusUrl, PHP_URL_PATH) === parse_url(route('payments.przelewy24.notification'), PHP_URL_PATH),
                'return_path_matches' => $settings !== null && parse_url($settings->returnUrl, PHP_URL_PATH) === parse_url(route('checkout.return'), PHP_URL_PATH),
                'external_connectivity' => 'not_checked',
            ],
            'processes' => ['queue_worker' => 'not_checked', 'scheduler' => 'not_checked', 'smtp' => 'not_checked'],
            'limit_per_section' => $limit,
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id, 'order_id' => $payment->order_id, 'status' => $payment->status->value,
                'verification_attempts' => $payment->verification_attempts,
                'last_verification_at_utc' => $payment->verification_last_attempt_at?->toISOString(),
                'verification_problem' => $payment->verification_failure_code !== null,
                'can_reconcile' => $settings !== null && $payment->provider_session_id !== null
                    && $payment->p24_environment === $settings->environment && $payment->p24_merchant_id === $settings->merchantId
                    && $payment->p24_pos_id === $settings->posId
                    && in_array($payment->status, [PaymentStatus::Initiated, PaymentStatus::Pending, PaymentStatus::Unknown], true),
            ]),
            'deliveries' => $deliveries->map(fn (DocumentDelivery $delivery): array => [
                'id' => $delivery->id, 'membership_id' => $delivery->membership_id, 'status' => $delivery->status->value,
                'attempts' => $delivery->attempts_count, 'max_attempts' => DocumentDelivery::MAX_ATTEMPTS,
                'needs_review' => $delivery->needs_review,
                'processing_expired' => $delivery->status === DeliveryStatus::Processing && $delivery->processing_started_at !== null
                    && $delivery->processing_started_at->addSeconds(DocumentDelivery::LEASE_SECONDS)->isPast(),
                'retry_exhausted' => $delivery->attempts_count >= DocumentDelivery::MAX_ATTEMPTS,
                'scheduled_for_utc' => $delivery->scheduled_for->toISOString(),
                'pdf_recorded' => $delivery->storage_path !== null,
            ]),
            'paid_orders_without_membership' => $missing,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
