<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRegistrationException;
use App\Exceptions\PaymentVerificationException;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Przelewy24Client
{
    public function verify(#[SensitiveParameter] Payment $payment, int $orderId, #[SensitiveParameter] Przelewy24Configuration $settings): void
    {
        try {
            $response = Http::withBasicAuth((string) $settings->posId, $settings->apiKey())->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(15)->put($settings->baseUrl().'/api/v1/transaction/verify', [
                    'merchantId' => $settings->merchantId, 'posId' => $settings->posId, 'sessionId' => $payment->provider_session_id,
                    'amount' => $payment->amount_grosz, 'currency' => $payment->currency, 'orderId' => $orderId,
                    'sign' => Przelewy24Signature::verification((string) $payment->provider_session_id, $orderId, $payment->amount_grosz, $payment->currency, $settings->crc()),
                ]);
        } catch (ConnectionException) {
            throw new PaymentVerificationException('verification_unavailable', true);
        }
        if ($response->status() !== 200 || ! in_array($response->json('responseCode'), [0, 0.0], true) || $response->json('data.status') !== 'success') {
            throw new PaymentVerificationException('verification_rejected', true);
        }
    }

    public function transaction(#[SensitiveParameter] Payment $payment, #[SensitiveParameter] Przelewy24Configuration $settings): ?Przelewy24Transaction
    {
        try {
            $response = Http::withBasicAuth((string) $settings->posId, $settings->apiKey())->acceptJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(15)->get($settings->baseUrl().'/api/v1/transaction/by/sessionId/'.rawurlencode((string) $payment->provider_session_id));
        } catch (ConnectionException) {
            throw new PaymentVerificationException('lookup_unavailable', true);
        }
        if ($response->status() === 404) {
            return null;
        }
        if ($response->status() !== 200 || ! in_array($response->json('responseCode'), [0, 0.0], true)) {
            throw new PaymentVerificationException('lookup_unavailable', true);
        }

        return Przelewy24Transaction::fromData($response->json('data'));
    }

    public function register(#[SensitiveParameter] Order $order, #[SensitiveParameter] Payment $payment, #[SensitiveParameter] Przelewy24Configuration $settings): string
    {
        if ($payment->order_id !== $order->id || ! is_string($payment->provider_session_id) || $payment->provider_session_id === '' || strlen($payment->provider_session_id) > 100) {
            throw new PaymentRegistrationException('registration_invalid_context', false);
        }
        $payload = [
            'merchantId' => $settings->merchantId, 'posId' => $settings->posId, 'sessionId' => $payment->provider_session_id,
            'amount' => $payment->amount_grosz, 'currency' => $payment->currency,
            'description' => 'Karnet ActiveFit', 'email' => $order->purchaser_email,
            'country' => 'PL', 'language' => 'pl', 'urlReturn' => $settings->returnUrlFor($order->public_reference), 'urlStatus' => $settings->statusUrl,
            'sign' => Przelewy24Signature::registration($payment->provider_session_id, $settings->merchantId, $payment->amount_grosz, $payment->currency, $settings->crc()),
            'encoding' => 'UTF-8',
        ];
        try {
            // Registration is not automatically POST-retried: a timeout does not prove rejection.
            $response = Http::withBasicAuth((string) $settings->posId, $settings->apiKey())->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(15)->post($settings->baseUrl().'/api/v1/transaction/register', $payload);
        } catch (ConnectionException) {
            throw new PaymentRegistrationException('registration_connection_error', true);
        }
        if (in_array($response->status(), [400, 401], true)) {
            throw new PaymentRegistrationException('registration_rejected', false);
        }
        $token = $response->json('data.token');
        if ($response->status() !== 200 || ! in_array($response->json('responseCode'), [0, 0.0], true)
            || ! is_string($token) || $token === '' || strlen($token) > 1024 || preg_match('/[\x00-\x20\x7F]/', $token)
            || in_array($token, ['.', '..'], true)) {
            throw new PaymentRegistrationException('registration_uncertain_response', true);
        }

        return $token;
    }

    public function redirectUrl(#[SensitiveParameter] Payment $payment): string
    {
        $settings = Przelewy24Configuration::load();
        $payment = $payment->exists ? $payment->fresh() : null;
        if ($payment === null) {
            throw ValidationException::withMessages(['payment' => 'Wybierz zapisaną płatność przed przekierowaniem.']);
        }
        if ($payment->provider !== PaymentProvider::Przelewy24 || $payment->status !== PaymentStatus::Pending || $payment->provider_token === null
            || $payment->p24_environment !== $settings->environment || $payment->p24_merchant_id !== $settings->merchantId || $payment->p24_pos_id !== $settings->posId) {
            throw ValidationException::withMessages(['payment' => 'Ta płatność nie jest gotowa do przekierowania. Wczytaj aktualny stan zamówienia.']);
        }

        return $settings->baseUrl().'/trnRequest/'.rawurlencode($payment->provider_token);
    }
}
