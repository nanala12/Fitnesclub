<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class Przelewy24Configuration
{
    private function __construct(
        public string $environment, public int $merchantId, public int $posId,
        #[SensitiveParameter] private string $apiKey,
        #[SensitiveParameter] private string $crc,
        public string $returnUrl, public string $statusUrl,
    ) {}

    public static function load(): self
    {
        $data = config('payments.przelewy24');
        $environment = $data['environment'] ?? null;
        $merchant = self::positiveId($data['merchant_id'] ?? null);
        $pos = self::positiveId($data['pos_id'] ?? null);
        if (($data['enabled'] ?? false) !== true || ! in_array($environment, ['sandbox', 'production'], true)
            || ($environment === 'production' && (! app()->isProduction() || ($data['live_enabled'] ?? false) !== true))
            || $merchant === false || $pos === false || ! is_string($data['api_key'] ?? null) || trim($data['api_key']) === ''
            || ! is_string($data['crc'] ?? null) || trim($data['crc']) === ''
            || ! self::validUrl($data['return_url'] ?? null) || ! self::validUrl($data['status_url'] ?? null)) {
            throw ValidationException::withMessages(['payment' => 'Płatności online nie są jeszcze dostępne. Skontaktuj się z klubem.']);
        }

        return new self($environment, $merchant, $pos, $data['api_key'], $data['crc'], $data['return_url'], $data['status_url']);
    }

    public function baseUrl(): string
    {
        return $this->environment === 'sandbox' ? 'https://sandbox.przelewy24.pl' : 'https://secure.przelewy24.pl';
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function crc(): string
    {
        return $this->crc;
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'merchantId' => $this->merchantId, 'posId' => $this->posId];
    }

    private static function positiveId(mixed $value): int|false
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[0-9]+$/D', $value))) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function validUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 250 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }
}
