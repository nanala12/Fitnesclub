<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { show as sale } from '@/routes/admin/sales';
import type { AuthenticatedPageProps, PaginationLink } from '@/types';

defineProps<{
    orderReference: string | null;
    entries: {
        data: {
            id: number;
            action: string;
            subjectLabel: string;
            occurredAt: string;
            actor: { id: number | null; name: string | null; label: string };
            changes: {
                label: string;
                before: string | null;
                after: string | null;
            }[];
            changedFields: string[];
            requestId: string | null;
        }[];
        links: PaginationLink[];
        total: number;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Historia zmian', href: AuditLogController.index() },
        ],
    },
});
const page = usePage<AuthenticatedPageProps>();
function date(instant: string) {
    return new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: page.props.businessTimezone,
    }).format(new Date(instant));
}
</script>
<template>
    <Head title="Historia zmian" />
    <p v-if="orderReference" class="mb-5 text-sm break-all">
        Historia zakupu {{ orderReference }} ·
        <Link :href="sale(orderReference)" class="underline"
            >Wróć do szczegółów sprzedaży</Link
        >
    </p>
    <div class="space-y-6">
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold">Historia zmian</h1>
            <p class="text-sm text-muted-foreground">
                Sprawdź, kto i kiedy wykonał operacje w klubie. Najnowsze zapisy
                są na początku listy.
            </p>
        </div>
        <EmptyState
            v-if="!entries.data.length"
            title="Brak zapisów historii"
            description="Po wykonaniu operacji wymagającej audytu pojawi się tutaj jej zapis."
        />
        <ol v-else class="space-y-4" aria-label="Zapisane operacje">
            <li
                v-for="entry in entries.data"
                :key="entry.id"
                class="min-w-0 space-y-4 rounded-2xl border border-border bg-card p-5 break-words"
                :data-audit-id="entry.id"
            >
                <div class="flex flex-wrap justify-between gap-3">
                    <h2 class="font-semibold">{{ entry.action }}</h2>
                    <time
                        :datetime="entry.occurredAt"
                        class="text-sm text-muted-foreground"
                        >{{ date(entry.occurredAt) }}</time
                    >
                </div>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Wykonawca</dt>
                        <dd>
                            {{
                                entry.actor.name ??
                                (entry.actor.label === 'System'
                                    ? 'System'
                                    : 'Konto niedostępne')
                            }}<span v-if="entry.actor.id">
                                · #{{ entry.actor.id }}</span
                            ><span v-if="entry.actor.label !== 'System'">
                                · {{ entry.actor.label }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Dotyczy</dt>
                        <dd>{{ entry.subjectLabel }}</dd>
                    </div>
                </dl>
                <p v-if="entry.changedFields.length" class="text-sm">
                    <span class="text-muted-foreground">Zmienione pola:</span>
                    {{ entry.changedFields.join(', ') }}
                </p>
                <ul
                    v-if="entry.changes.length"
                    class="space-y-2 rounded-lg bg-muted p-3 text-sm"
                    aria-label="Zmiany wartości"
                >
                    <li v-for="change in entry.changes" :key="change.label">
                        <span class="font-medium">{{ change.label }}:</span>
                        {{ change.before ?? 'Brak wcześniejszej wartości' }} →
                        {{ change.after ?? 'Brak wartości' }}
                    </li>
                </ul>
                <p v-if="entry.requestId" class="text-xs text-muted-foreground">
                    Identyfikator operacji: {{ entry.requestId }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Zapis #{{ entry.id }}
                </p>
            </li>
        </ol>
        <Pagination :links="entries.links" />
    </div>
</template>
