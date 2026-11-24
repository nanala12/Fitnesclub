<?php

namespace App\Actions\Payments;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Exceptions\PaymentRegistrationException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Przelewy24Client;
use App\Services\Payments\Przelewy24Configuration;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class InitiatePrzelewy24Payment
{
    public function __construct(private Przelewy24Client $client, private RecordAudit $audit) {}

    /**
     * Internal checkout boundary, not an HTTP endpoint. Null explicitly selects trusted guest
     * checkout; its caller must own the just-created order or verify scoped guest access.
     * Registered purchases require their authenticated purchaser, not merely the holder.
     */
    public function handle(int $orderId, ?User $purchaser): Payment
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Commit the order before registering a remote payment.');
        }
        [$order, $payment, $settings, $send] = DB::transaction(function () use ($orderId, $purchaser): array {
            if ($purchaser !== null) {
                if (! $purchaser->exists) {
                    throw new AuthorizationException;
                }
                $purchaser = User::query()->whereKey($purchaser->id)->lockForUpdate()->first();
                if ($purchaser === null) {
                    throw new AuthorizationException;
                }
            }
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($purchaser === null) {
                if ($order->purchaser_user_id !== null) {
                    throw new AuthorizationException;
                }
            } else {
                Gate::forUser($purchaser)->authorize('initiateOnlinePayment', $order);
            }
            if ($order->sales_channel !== SalesChannel::Online || $order->payment_method !== PaymentMethod::Przelewy24
                || $order->status !== OrderStatus::Pending || $order->paid_at !== null
                || $order->accepted_regulation_version_id === null || $order->regulation_accepted_at === null) {
                throw ValidationException::withMessages(['payment' => 'To zamówienie nie oczekuje na rozpoczęcie płatności online.']);
            }
            if (($order->plan_snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($order->plan_snapshot['currency'] ?? null) !== $order->currency) {
                throw ValidationException::withMessages(['payment' => 'Zapisane warunki zakupu wymagają sprawdzenia przed płatnością.']);
            }
            Validator::make(['purchaser' => ['email' => $order->purchaser_email]], ['purchaser.email' => ['required', 'string', 'email', 'max:50']],
                ['purchaser.email.max' => 'Przelewy24 obsługuje adres e-mail kupującego o długości do 50 znaków.'])->validate();
            $settings = Przelewy24Configuration::load();
            $settings->returnUrlFor($order->public_reference);
            $payments = $order->payments()->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $attempt) {
                if ($attempt->provider !== PaymentProvider::Przelewy24 || $attempt->method !== PaymentMethod::Przelewy24
                    || $attempt->amount_grosz !== $order->amount_grosz || $attempt->currency !== $order->currency) {
                    throw ValidationException::withMessages(['payment' => 'Zapis płatności wymaga sprawdzenia przed kolejną próbą.']);
                }
            }
            $active = $payments->filter(fn (Payment $attempt): bool => ! in_array($attempt->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true));
            if ($active->count() > 1) {
                throw ValidationException::withMessages(['payment' => 'Zapis płatności wymaga sprawdzenia przed kolejną próbą.']);
            }
            if (($payment = $active->first()) !== null) {
                if ($payment->p24_environment !== $settings->environment || $payment->p24_merchant_id !== $settings->merchantId || $payment->p24_pos_id !== $settings->posId) {
                    throw ValidationException::withMessages(['payment' => 'Konfiguracja operatora została zmieniona. Ta płatność wymaga sprawdzenia.']);
                }
                if ($payment->status === PaymentStatus::Initiated && $payment->registration_started_at !== null
                    && $payment->registration_started_at->addSeconds(60)->isPast()) {
                    $payment->forceFill(['status' => PaymentStatus::Unknown, 'failure_code' => 'registration_interrupted', 'registration_finished_at' => CarbonImmutable::now('UTC')])->save();
                    $this->audit->handle(AuditAction::PaymentRegistrationFailed, $payment->id, null, null, ['payment_status' => 'unknown']);
                }

                return [$order, $payment, $settings, false];
            }
            if ($payments->count() >= 3) {
                throw ValidationException::withMessages(['payment' => 'Osiągnięto limit prób przygotowania płatności. Skontaktuj się z klubem.']);
            }
            $payment = new Payment;
            $payment->forceFill(['order_id' => $order->id, 'provider' => PaymentProvider::Przelewy24, 'method' => PaymentMethod::Przelewy24,
                'amount_grosz' => $order->amount_grosz, 'currency' => $order->currency, 'status' => PaymentStatus::Initiated,
                'provider_session_id' => (string) Str::uuid(), 'provider_transaction_id' => null,
                'p24_environment' => $settings->environment, 'p24_merchant_id' => $settings->merchantId, 'p24_pos_id' => $settings->posId,
                'registration_started_at' => CarbonImmutable::now('UTC'), 'registration_finished_at' => null, 'provider_token' => null,
                'confirmed_at' => null, 'confirmed_by_user_id' => null, 'failure_code' => null])->save();
            // Gateway transport is a system operation after purchaser scope is checked;
            // purchaser attribution remains immutable on the order, including guest purchases.
            $this->audit->handle(AuditAction::PaymentRegistrationStarted, $payment->id, null, null,
                ['payment_status' => 'initiated', 'price_grosz' => $payment->amount_grosz]);

            return [$order, $payment, $settings, true];
        }, 3);
        if (! $send) {
            if ($payment->status === PaymentStatus::Pending && $payment->provider_token !== null) {
                return $payment;
            }
            $this->notReady($payment);
        }
        try {
            $token = $this->client->register($order, $payment, $settings);
        } catch (PaymentRegistrationException $exception) {
            $this->finishFailure($order->id, $payment->id, $exception);
            throw ValidationException::withMessages(['payment' => $exception->uncertain
                ? 'Nie znamy jeszcze wyniku przygotowania płatności. Wymaga on sprawdzenia przed kolejną próbą. Skontaktuj się z klubem.'
                : 'Operator nie przyjął przygotowania płatności. Spróbuj później lub skontaktuj się z klubem.']);
        }
        try {
            $payment = DB::transaction(function () use ($order, $payment, $token): Payment {
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== OrderStatus::Pending || $payment->status !== PaymentStatus::Initiated) {
                    return $payment;
                }
                $payment->forceFill(['provider_token' => $token, 'status' => PaymentStatus::Pending, 'registration_finished_at' => CarbonImmutable::now('UTC')])->save();
                $this->audit->handle(AuditAction::PaymentRegistrationReady, $payment->id, null, ['payment_status' => 'initiated'], ['payment_status' => 'pending']);

                return $payment;
            }, 3);
        } catch (Throwable) {
            // The committed session remains discoverable. Never repeat the POST after a lost result.
            throw new PaymentRegistrationException('registration_persistence_error', true);
        }
        if ($payment->status !== PaymentStatus::Pending) {
            $this->notReady($payment);
        }

        return $payment;
    }

    private function finishFailure(int $orderId, int $paymentId, PaymentRegistrationException $error): void
    {
        DB::transaction(function () use ($orderId, $paymentId, $error): void {
            Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Initiated) {
                return;
            }
            $payment->forceFill(['status' => $error->uncertain ? PaymentStatus::Unknown : PaymentStatus::Failed,
                'failure_code' => $error->failureCode, 'registration_finished_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->handle(AuditAction::PaymentRegistrationFailed, $payment->id, null, ['payment_status' => 'initiated'], ['payment_status' => $payment->status->value]);
        }, 3);
    }

    private function notReady(Payment $payment): never
    {
        throw ValidationException::withMessages(['payment' => $payment->status === PaymentStatus::Initiated
            ? 'Przygotowanie płatności już trwa. Sprawdź stan zamówienia za chwilę.'
            : 'Stan tej płatności wymaga sprawdzenia przed kolejną próbą. Skontaktuj się z klubem.']);
    }
}
