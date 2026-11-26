<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { businessDate, orderTitles } from '@/lib/orderPresentation';
import { create } from '@/routes/checkout';
import type { OwnMembership } from '@/types/membership';
import type { PaginationLink } from '@/types/pagination';
defineProps<{
    memberships: {
        data: OwnMembership[];
        links: PaginationLink[];
        total: number;
    };
}>();
</script>
<template>
    <Head title="Moje karnety" />
    <header class="mb-7 flex flex-wrap justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold">Moje karnety</h1>
            <p class="mt-2 max-w-2xl text-muted-foreground">
                Karnety przypisane do Twojego konta oraz kupione dla innych
                osób. Wcześniejsze zakupy gościnne pozostają oddzielne.
            </p>
        </div>
        <ActionButton as-child
            ><Link :href="create()">Kup karnet</Link></ActionButton
        >
    </header>
    <div v-if="memberships.data.length" class="grid gap-4 lg:grid-cols-2">
        <article
            v-for="membership in memberships.data"
            :key="membership.id"
            class="min-w-0 rounded-2xl border border-border bg-card p-5 sm:p-6"
        >
            <p class="text-sm text-muted-foreground">
                {{
                    membership.forAnotherPerson
                        ? 'Kupiony dla innej osoby'
                        : 'Twój karnet'
                }}
            </p>
            <h2 class="mt-2 text-xl font-bold break-words">
                {{ membership.planName }}
            </h2>
            <p class="mt-2 break-words">
                Posiadacz: {{ membership.holderName }}
            </p>
            <p class="mt-4 font-semibold">
                {{ orderTitles[membership.state] }}
            </p>
            <p
                v-if="membership.state === 'pending_activation'"
                class="mt-3 text-sm text-muted-foreground"
            >
                Ważność rozpocznie się przy pierwszej wizycie w klubie.
            </p>
            <p v-if="membership.startsOn" class="mt-3 text-sm">
                Od {{ businessDate(membership.startsOn)
                }}<span v-if="membership.expiresOn">
                    do {{ businessDate(membership.expiresOn) }} (włącznie)</span
                >
            </p>
            <p v-if="membership.entriesRemaining !== null" class="mt-3 text-sm">
                Pozostałe wejścia:
                <strong>{{ membership.entriesRemaining }}</strong> · bez
                ograniczenia czasowego
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
                <ActionButton as-child variant="outline"
                    ><Link :href="membership.showUrl"
                        >Szczegóły i historia</Link
                    ></ActionButton
                ><ActionButton
                    v-if="membership.pdfUrl"
                    as-child
                    variant="outline"
                    ><a :href="membership.pdfUrl">Pobierz PDF</a></ActionButton
                >
            </div>
        </article>
    </div>
    <EmptyState
        v-else
        title="Nie masz jeszcze karnetów"
        description="Po potwierdzeniu opłaty karnet pojawi się tutaj. Zakupy gościnne nie są dopisywane do konta."
    />
    <Pagination :links="memberships.links" />
</template>
