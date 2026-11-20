<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditPayload;
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
        foreach (['role' => 'Rola', 'suspended' => 'Dostęp do konta'] as $field => $label) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                $changes[] = ['label' => $label, 'before' => $this->displayValue($old[$field] ?? null), 'after' => $this->displayValue($new[$field] ?? null)];
            }
        }

        return [
            'id' => $entry->id, 'action' => $action?->label() ?? 'Zdarzenie audytu',
            'subjectLabel' => match ($action?->subjectType()) {
                'club_settings' => 'Ustawienia klubu',
                'class_type' => 'Rodzaj zajęć #'.$entry->getAttribute('subject_id'),
                'class_slot' => 'Termin zajęć #'.$entry->getAttribute('subject_id'),
                'regulation_version' => 'Wersja regulaminu #'.$entry->getAttribute('subject_id'),
                'user' => 'Konto #'.$entry->getAttribute('subject_id'),
                'gallery_category' => 'Kategoria galerii #'.$entry->getAttribute('subject_id'),
                'gallery_image' => 'Zdjęcie galerii #'.$entry->getAttribute('subject_id'),
                'news_post' => 'Aktualność #'.$entry->getAttribute('subject_id'),
                'gallery_category' => 'Kategoria galerii #'.$entry->getAttribute('subject_id'),
                'gallery_image' => 'Zdjęcie galerii #'.$entry->getAttribute('subject_id'),
                'news_post' => 'Aktualność #'.$entry->getAttribute('subject_id'),
                'homepage_tile' => 'Kafelek #'.$entry->getAttribute('subject_id'),
                'homepage_slide' => 'Slajd #'.$entry->getAttribute('subject_id'),
                'media_asset' => 'Zdjęcie #'.$entry->getAttribute('subject_id'),
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

    private function displayValue(bool|string|null $value): ?string
    {
        return match ($value) {
            true => 'Zawieszony', false => 'Włączony', 'admin' => 'Administrator',
            'employee' => 'Pracownik', 'customer' => 'Klient', default => null,
        };
    }
}
