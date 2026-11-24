<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentVerificationException;
use SensitiveParameter;

final readonly class Przelewy24Notification
{
    private function __construct(
        public int $merchantId, public int $posId, public string $sessionId,
        public int $amount, public int $originAmount, public string $currency,
        public int $orderId, public int $methodId, public string $statement,
        public string $sign,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        foreach (['merchantId', 'posId', 'amount', 'originAmount', 'orderId', 'methodId'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < (in_array($field, ['merchantId', 'posId', 'orderId'], true) ? 1 : 0)) {
                throw new PaymentVerificationException('invalid_notification');
            }
        }
        if (! is_string($data['sessionId'] ?? null) || strlen($data['sessionId']) < 1 || strlen($data['sessionId']) > 100
            || ! is_string($data['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])
            || ! is_string($data['statement'] ?? null) || strlen($data['statement']) > 4096
            || ! is_string($data['sign'] ?? null) || ! preg_match('/^[a-fA-F0-9]{96}$/D', $data['sign'])) {
            throw new PaymentVerificationException('invalid_notification');
        }

        return new self($data['merchantId'], $data['posId'], $data['sessionId'], $data['amount'], $data['originAmount'],
            $data['currency'], $data['orderId'], $data['methodId'], $data['statement'], strtolower($data['sign']));
    }

    public function authenticate(#[SensitiveParameter] Przelewy24Configuration $settings): void
    {
        if ($this->merchantId !== $settings->merchantId || $this->posId !== $settings->posId
            || ! hash_equals(Przelewy24Signature::notification($this, $settings->crc()), $this->sign)) {
            throw new PaymentVerificationException('invalid_signature');
        }
    }
}
