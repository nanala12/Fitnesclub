<?php

namespace App\Support;

use App\Enums\UserRole;

class AuditPayload
{
    /** @var array<string, string> */
    public const array FIELD_LABELS = [
        'body' => 'Treść', 'excerpt' => 'Wstęp', 'published_at' => 'Data publikacji',
        'image' => 'Zdjęcie', 'title' => 'Tytuł', 'description' => 'Opis',
        'is_published' => 'Widoczność', 'sort_order' => 'Kolejność', 'link_url' => 'Odnośnik',
        'first_name' => 'Imię', 'last_name' => 'Nazwisko', 'email' => 'Adres e-mail',
        'phone' => 'Telefon', 'role' => 'Rola', 'suspended_at' => 'Dostęp do konta',
    ];

    /**
     * Keep explicitly allowed, typed values only; contact values and nested/unknown data are omitted.
     * Extend this allowlist deliberately when adding a new domain's audit events.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, bool|string>|null
     */
    public function values(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        $safe = [];
        if (isset($values['role']) && is_string($values['role']) && UserRole::tryFrom($values['role']) !== null) {
            $safe['role'] = $values['role'];
        }
        if (isset($values['suspended']) && is_bool($values['suspended'])) {
            $safe['suspended'] = $values['suspended'];
        }

        return $safe;
    }

    /** @param array<string, mixed> $metadata
     * @return array{changed_fields: list<string>, request_id?: string}
     */
    public function metadata(array $metadata): array
    {
        $fields = $metadata['changed_fields'] ?? [];
        $safe = ['changed_fields' => is_array($fields)
            ? array_values(array_unique(array_filter($fields, fn (mixed $field): bool => is_string($field) && array_key_exists($field, self::FIELD_LABELS))))
            : []];
        $requestId = $metadata['request_id'] ?? null;
        if (is_string($requestId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestId)) {
            $safe['request_id'] = strtolower($requestId);
        }

        return $safe;
    }
}
