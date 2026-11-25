<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditPayload;
use App\Support\PlanPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditPayload $payload): Response
    {
        Gate::authorize('viewAny', AuditLog::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('admin/audit/Index', [
            'entries' => AuditLog::query()->with('actor:id,name')->orderByDesc('id')->paginate(20)
                ->through(fn (AuditLog $entry): array => $this->present($entry, $payload)),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AuditLog $entry, AuditPayload $payload): array
    {
        $action = AuditAction::tryFrom($entry->getAttribute('action'));
        $old = $payload->values(is_array($entry->getAttribute('old_values')) ? $entry->getAttribute('old_values') : null) ?? [];
        $new = $payload->values(is_array($entry->getAttribute('new_values')) ? $entry->getAttribute('new_values') : null) ?? [];
        $metadata = $payload->metadata(is_array($entry->getAttribute('metadata')) ? $entry->getAttribute('metadata') : []);
        $changes = [];
        foreach (['payment_status' => 'Stan płatności', 'payment_method' => 'Forma płatności', 'sales_channel' => 'Kanał sprzedaży', 'entries_remaining' => 'Pozostałe wejścia', 'starts_on' => 'Początek ważności', 'expires_on' => 'Koniec ważności', 'role' => 'Rola', 'suspended' => 'Dostęp do konta', 'price_grosz' => 'Cena', 'is_active' => 'Dostępność planu', 'is_public' => 'Widoczność w cenniku', 'is_featured' => 'Wyróżnienie'] as $field => $label) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                $changes[] = ['label' => $label, 'before' => $this->displayValue($old[$field] ?? null, $field), 'after' => $this->displayValue($new[$field] ?? null, $field)];
            }
        }

        return [
            'id' => $entry->id, 'action' => $action?->label() ?? 'Zdarzenie audytu',
            'subjectLabel' => match ($action?->subjectType()) {
                'payment' => 'Płatność #'.$entry->getAttribute('subject_id'),
                'order' => 'Zamówienie #'.$entry->getAttribute('subject_id'),
                'membership' => 'Karnet #'.$entry->getAttribute('subject_id'),
                'price_category' => 'Kategoria cennika #'.$entry->getAttribute('subject_id'),
                'price_group' => 'Grupa cennika #'.$entry->getAttribute('subject_id'),
                'price_service' => 'Usługa cennika #'.$entry->getAttribute('subject_id'),
                'membership_plan' => 'Plan karnetu #'.$entry->getAttribute('subject_id'),
                'club_settings' => 'Ustawienia klubu',
                'class_type' => 'Rodzaj zajęć #'.$entry->getAttribute('subject_id'),
                'class_slot' => 'Termin zajęć #'.$entry->getAttribute('subject_id'),
                'regulation_version' => 'Wersja regulaminu #'.$entry->getAttribute('subject_id'),
                'user' => 'Konto #'.$entry->getAttribute('subject_id'),
                'gallery_category' => 'Kategoria galerii #'.$entry->getAttribute('subject_id'),
                'gallery_image' => 'Zdjęcie galerii #'.$entry->getAttribute('subject_id'),
                'news_post' => 'Aktualność #'.$entry->getAttribute('subject_id'),
                'homepage_tile' => 'Kafelek #'.$entry->getAttribute('subject_id'),
                'homepage_slide' => 'Slajd #'.$entry->getAttribute('subject_id'),
                'media_asset' => 'Zdjęcie #'.$entry->getAttribute('subject_id'),
                'document_delivery' => 'Wysyłka PDF #'.$entry->getAttribute('subject_id'),
                default => 'Zapis historyczny',
            },
            'occurredAt' => $entry->occurred_at->toISOString(),
            'actor' => [
                'id' => $entry->getAttribute('actor_user_id'), 'name' => $entry->actor?->name,
                'label' => match ($entry->getAttribute('actor_type')) {
                    'admin' => 'Administrator', 'employee' => 'Pracownik', 'system' => 'System', default => 'Nieznany wykonawca',
                },
            ],
            'changes' => $changes,
            'changedFields' => array_map(fn (string $field): string => AuditPayload::FIELD_LABELS[$field], $metadata['changed_fields']),
            'requestId' => $metadata['request_id'] ?? null,
        ];
    }

    private function displayValue(bool|string|int|null $value, string $field): ?string
    {
        if ($field === 'entries_remaining' && is_int($value)) {
            return (string) $value;
        }
        if (in_array($field, ['starts_on', 'expires_on'], true) && is_string($value)) {
            return implode('.', array_reverse(explode('-', $value)));
        }
        if ($field === 'price_grosz' && is_int($value)) {
            return PlanPrice::label($value);
        }
        if (in_array($field, ['is_active', 'is_public', 'is_featured'], true) && is_bool($value)) {
            return $value ? 'Tak' : 'Nie';
        }

        return match ($value) {
            'initiated' => 'Przygotowywanie', 'pending' => 'Oczekuje na płatność', 'unknown' => 'Wymaga sprawdzenia',
            'failed' => 'Nieudana', 'confirmed' => 'Potwierdzona', 'cancelled' => 'Anulowana',
            'cash' => 'Gotówka', 'card' => 'Karta', 'przelewy24' => 'Przelewy24',
            'reception' => 'Recepcja', 'online' => 'Online',
            true => 'Zawieszony', false => 'Włączony', 'admin' => 'Administrator',
            'employee' => 'Pracownik', 'customer' => 'Klient', default => null,
        };
    }
}
