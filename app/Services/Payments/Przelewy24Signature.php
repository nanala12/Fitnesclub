<?php

namespace App\Services\Payments;

use SensitiveParameter;

class Przelewy24Signature
{
    public static function registration(string $sessionId, int $merchantId, int $amount, string $currency, #[SensitiveParameter] string $crc): string
    {
        return hash('sha384', json_encode(['sessionId' => $sessionId, 'merchantId' => $merchantId, 'amount' => $amount, 'currency' => $currency, 'crc' => $crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
