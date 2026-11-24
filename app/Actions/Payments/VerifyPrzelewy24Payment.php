<?php

namespace App\Actions\Payments;

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\IssuePaidMembership;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Exceptions\PaymentVerificationException;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Przelewy24Client;
use App\Services\Payments\Przelewy24Configuration;
use App\Services\Payments\Przelewy24Notification;
use App\Services\Payments\Przelewy24Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

class VerifyPrzelewy24Payment
{
    public function __construct(private Przelewy24Client $client, private IssuePaidMembership $memberships, private RecordAudit $audit) {}

    public function handle(#[SensitiveParameter] Przelewy24Notification $notification): Membership
    {
        $this->requireCommittedContext();
        $settings = Przelewy24Configuration::load();
        $notification->authenticate($settings);
        $payment = Payment::query()->where('provider', PaymentProvider::Przelewy24)->where('provider_session_id', $notification->sessionId)->first();
        if ($payment === null) {
            throw new PaymentVerificationException('unknown_session');
        }
        if ($notification->amount !== $payment->amount_grosz || $notification->originAmount !== $payment->amount_grosz || $notification->currency !== $payment->currency) {
            throw new PaymentVerificationException('notification_mismatch');
        }
        $payment = $this->reserve($payment->id, $notification->orderId, $settings);
        if ($payment->status === PaymentStatus::Confirmed) {
            return $this->finalize($payment->id, $notification->orderId, $settings);
        }

        return $this->verifyAndIssue($payment, $notification->orderId, $settings);
    }

    /** Trusted operational boundary only; never expose an unscoped payment ID to a browser. */
    public function reconcile(int $paymentId): ?Membership
    {
        $this->requireCommittedContext();
        $settings = Przelewy24Configuration::load();
        $payment = Payment::query()->findOrFail($paymentId);
        if ($payment->status === PaymentStatus::Confirmed) {
            return $this->finalize($payment->id, (int) $payment->provider_transaction_id, $settings);
        }
        try {
            $payment = $this->reserve($payment->id, null, $settings);
            if ($payment->status === PaymentStatus::Confirmed) {
                return $this->finalize($payment->id, (int) $payment->provider_transaction_id, $settings);
            }
            $transaction = $this->client->transaction($payment, $settings);
            if ($transaction === null) {
                // A missing registration is not proof that another POST is safe.
                return null;
            }
            $this->matchingTransaction($payment, $transaction);
            if ($transaction->status === 0) {
                return null;
            }
            if ($transaction->status === 3) {
                throw new PaymentVerificationException('returned_payment_requires_review');
            }
            $payment = $this->reserve($payment->id, $transaction->orderId, $settings, false);
            if ($transaction->status === 2 || $payment->status === PaymentStatus::Confirmed) {
                return $this->finalize($payment->id, $transaction->orderId, $settings);
            }

            return $this->verifyAndIssue($payment, $transaction->orderId, $settings);
        } catch (PaymentVerificationException $exception) {
            $this->failure($payment->id, $exception->failureCode);
            throw $exception;
        }
    }

    private function verifyAndIssue(#[SensitiveParameter] Payment $payment, int $transactionId, #[SensitiveParameter] Przelewy24Configuration $settings): Membership
    {
        try {
            try {
                $this->client->verify($payment, $transactionId, $settings);
            } catch (PaymentVerificationException $exception) {
                // Verification may have succeeded remotely before its response was lost.
                $transaction = $this->client->transaction($payment, $settings);
                if ($transaction === null) {
                    throw $exception;
                }
                $this->matchingTransaction($payment, $transaction);
                if ($transaction->status !== 2 || $transaction->orderId !== $transactionId) {
                    throw $exception;
                }
            }

            return $this->finalize($payment->id, $transactionId, $settings);
        } catch (PaymentVerificationException $exception) {
            $this->failure($payment->id, $exception->failureCode);
            throw $exception;
        }
    }

    private function reserve(int $paymentId, ?int $transactionId, #[SensitiveParameter] Przelewy24Configuration $settings, bool $recordAttempt = true): Payment
    {
        try {
            return DB::transaction(function () use ($paymentId, $transactionId, $settings, $recordAttempt): Payment {
                $locator = Payment::query()->findOrFail($paymentId);
                $order = Order::query()->whereKey($locator->order_id)->lockForUpdate()->firstOrFail();
                $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
                $this->context($payment, $order, $settings);
                if ($transactionId !== null && $payment->provider_transaction_id !== null && $payment->provider_transaction_id !== (string) $transactionId) {
                    throw new PaymentVerificationException('transaction_conflict');
                }
                if ($payment->status !== PaymentStatus::Confirmed) {
                    $payment->forceFill(['provider_transaction_id' => $transactionId === null ? $payment->provider_transaction_id : (string) $transactionId,
                        'verification_attempts' => $payment->verification_attempts + ($recordAttempt ? 1 : 0),
                        'verification_last_attempt_at' => CarbonImmutable::now('UTC'), 'verification_failure_code' => null])->save();
                }

                return $payment;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new PaymentVerificationException('transaction_conflict');
            }
            throw new PaymentVerificationException('verification_persistence_error', true);
        }
    }

    private function finalize(int $paymentId, int $transactionId, #[SensitiveParameter] Przelewy24Configuration $settings): Membership
    {
        try {
            return DB::transaction(function () use ($paymentId, $transactionId, $settings): Membership {
                $locator = Payment::query()->findOrFail($paymentId);
                $hint = Order::query()->findOrFail($locator->order_id);
                // Match the established renewal lock order: root, then target order/payment.
                if ($hint->renewal_source_membership_id !== null) {
                    $source = Membership::query()->findOrFail($hint->renewal_source_membership_id);
                    $root = $source->renewal_root_membership_id === null ? $source : Membership::query()->findOrFail($source->renewal_root_membership_id);
                    Order::query()->whereKey($root->order_id)->lockForUpdate()->firstOrFail();
                }
                $order = Order::query()->whereKey($hint->id)->lockForUpdate()->firstOrFail();
                $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
                $this->context($payment, $order, $settings);
                if ($payment->provider_transaction_id !== (string) $transactionId || $order->renewal_source_membership_id !== $hint->renewal_source_membership_id) {
                    throw new PaymentVerificationException('transaction_conflict');
                }
                if ($payment->status === PaymentStatus::Confirmed) {
                    if ($order->status !== OrderStatus::Paid || $order->paid_at === null || $payment->confirmed_at === null) {
                        throw new PaymentVerificationException('inconsistent_confirmation');
                    }

                    return $this->memberships->handle($order->id, null);
                }
                if ($order->status !== OrderStatus::Pending || $order->paid_at !== null
                    || $order->payments()->where('status', PaymentStatus::Confirmed)->lockForUpdate()->exists()) {
                    throw new PaymentVerificationException('inconsistent_confirmation');
                }
                $now = CarbonImmutable::now('UTC');
                $payment->forceFill(['status' => PaymentStatus::Confirmed, 'confirmed_at' => $now,
                    'confirmed_by_user_id' => null, 'verification_failure_code' => null])->save();
                $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $now])->save();
                $membership = $this->memberships->handle($order->id, null);
                $this->audit->handle(AuditAction::PaymentVerified, $payment->id, null, null,
                    ['payment_status' => 'confirmed', 'price_grosz' => $payment->amount_grosz]);

                return $membership;
            }, 3);
        } catch (PaymentVerificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PaymentVerificationException('issuance_persistence_error', true);
        }
    }

    private function context(#[SensitiveParameter] Payment $payment, #[SensitiveParameter] Order $order, #[SensitiveParameter] Przelewy24Configuration $settings): void
    {
        if ($payment->provider !== PaymentProvider::Przelewy24 || $payment->method !== PaymentMethod::Przelewy24
            || $order->sales_channel !== SalesChannel::Online || $order->payment_method !== PaymentMethod::Przelewy24
            || $payment->p24_environment !== $settings->environment || $payment->p24_merchant_id !== $settings->merchantId || $payment->p24_pos_id !== $settings->posId
            || ! is_string($payment->provider_session_id) || $payment->provider_session_id === ''
            || $payment->amount_grosz !== $order->amount_grosz || $payment->currency !== $order->currency
            || ($order->plan_snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($order->plan_snapshot['currency'] ?? null) !== $order->currency
            || $order->accepted_regulation_version_id === null || $order->regulation_accepted_at === null
            || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Paid], true)
            || ! in_array($payment->status, [PaymentStatus::Initiated, PaymentStatus::Pending, PaymentStatus::Unknown, PaymentStatus::Confirmed], true)) {
            throw new PaymentVerificationException('payment_context_mismatch');
        }
    }

    private function matchingTransaction(#[SensitiveParameter] Payment $payment, #[SensitiveParameter] Przelewy24Transaction $transaction): void
    {
        if ($transaction->sessionId !== $payment->provider_session_id || $transaction->amount !== $payment->amount_grosz || $transaction->currency !== $payment->currency
            || ($payment->provider_transaction_id !== null && $payment->provider_transaction_id !== (string) $transaction->orderId)) {
            throw new PaymentVerificationException('provider_state_mismatch');
        }
    }

    private function failure(int $paymentId, string $code): void
    {
        DB::transaction(function () use ($paymentId, $code): void {
            $locator = Payment::query()->findOrFail($paymentId);
            Order::query()->whereKey($locator->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, [PaymentStatus::Initiated, PaymentStatus::Pending, PaymentStatus::Unknown], true)) {
                $payment->forceFill(['status' => PaymentStatus::Unknown, 'verification_failure_code' => $code])->save();
            }
        }, 3);
    }

    private function requireCommittedContext(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Payment provider verification requires committed data outside a database transaction.');
        }
    }
}
