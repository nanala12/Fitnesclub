<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentVerificationException;
use SensitiveParameter;

final readonly class Przelewy24Transaction
{
    private function __construct(public string $sessionId, public int $orderId, public int $amount, public string $currency, public int $status) {}

    public static function fromData(#[SensitiveParameter] mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['sessionId'] ?? null) || strlen($data['sessionId']) < 1 || strlen($data['sessionId']) > 100
            || ! is_int($data['orderId'] ?? null) || $data['orderId'] < 0
            || ! is_int($data['amount'] ?? null) || $data['amount'] < 0
            || ! is_string($data['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])
            || ! in_array($data['status'] ?? null, [0, 1, 2, 3], true)
            || (in_array($data['status'], [1, 2, 3], true) && $data['orderId'] < 1)) {
            throw new PaymentVerificationException('invalid_provider_state', true);
        }

        return new self($data['sessionId'], $data['orderId'], $data['amount'], $data['currency'], $data['status']);
    }
}
