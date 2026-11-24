<?php

namespace App\Services\Payments;

use SensitiveParameter;

class Przelewy24Signature
{
    public static function notification(#[SensitiveParameter] Przelewy24Notification $notification, #[SensitiveParameter] string $crc): string
    {
        return hash('sha384', json_encode([
            'merchantId' => $notification->merchantId, 'posId' => $notification->posId, 'sessionId' => $notification->sessionId,
            'amount' => $notification->amount, 'originAmount' => $notification->originAmount, 'currency' => $notification->currency,
            'orderId' => $notification->orderId, 'methodId' => $notification->methodId, 'statement' => $notification->statement, 'crc' => $crc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function verification(string $sessionId, int $orderId, int $amount, string $currency, #[SensitiveParameter] string $crc): string
    {
        return hash('sha384', json_encode(['sessionId' => $sessionId, 'orderId' => $orderId, 'amount' => $amount, 'currency' => $currency, 'crc' => $crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function registration(string $sessionId, int $merchantId, int $amount, string $currency, #[SensitiveParameter] string $crc): string
    {
        return hash('sha384', json_encode(['sessionId' => $sessionId, 'merchantId' => $merchantId, 'amount' => $amount, 'currency' => $currency, 'crc' => $crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
