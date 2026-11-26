<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { businessDate, orderDate, orderTitles } from '@/lib/orderPresentation';
import { index } from '@/routes/memberships';
import type { OwnMembership } from '@/types/membership';
import type { PaginationLink } from '@/types/pagination';
defineProps<{
    membership: OwnMembership;
    activatedAt: string | null;
    activatedBy: string | null;
    entries: {
        data: { id: number; usedAt: string; actor: string }[];
        links: PaginationLink[];
        total: number;
    };
}>();
</script>
<template>
    <Head title="Szczegóły karnetu" />
    <Link :href="index()" class="text-sm underline">← Moje karnety</Link>
    <section
        class="mt-6 max-w-3xl rounded-2xl border border-border bg-card p-5 sm:p-7"
    >
        <p class="text-sm text-muted-foreground">
            {{
                membership.forAnotherPerson
                    ? 'Kupiony dla innej osoby'
                    : 'Twój karnet'
            }}
        </p>
        <h1 class="mt-2 text-3xl font-bold break-words">
            {{ membership.planName }}
        </h1>
        <p class="mt-3 break-words">Posiadacz: {{ membership.holderName }}</p>
        <p class="mt-4 font-semibold">{{ orderTitles[membership.state] }}</p>
        <p
            v-if="membership.state === 'pending_activation'"
            class="mt-4 text-muted-foreground"
        >
            Ważność rozpocznie się przy pierwszej wizycie posiadacza w klubie.
            Karnet aktywuje pracownik; aktywacja nie odlicza wejścia.
        </p>
        <p
            v-if="membership.state === 'scheduled'"
            class="mt-4 text-muted-foreground"
        >
            Przedłużenie rozpocznie się w ustalonym terminie, bez ponownej
            aktywacji w recepcji.
        </p>
        <dl class="my-6 grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Zakup</dt>
                <dd>{{ orderDate(membership.purchasedAt) }}</dd>
            </div>
            <div v-if="membership.startsOn">
                <dt class="text-muted-foreground">Ważny od</dt>
                <dd>{{ businessDate(membership.startsOn) }}</dd>
            </div>
            <div v-if="membership.expiresOn">
                <dt class="text-muted-foreground">Ważny do (włącznie)</dt>
                <dd>{{ businessDate(membership.expiresOn) }}</dd>
            </div>
            <div v-if="membership.entriesRemaining !== null">
                <dt class="text-muted-foreground">Pozostałe wejścia</dt>
                <dd>
                    {{ membership.entriesRemaining }} · bez ograniczenia
                    czasowego
                </dd>
            </div>
            <div v-if="activatedAt">
                <dt class="text-muted-foreground">
                    Aktywacja przez pracownika
                </dt>
                <dd>{{ orderDate(activatedAt) }} · {{ activatedBy }}</dd>
            </div>
        </dl>
        <ActionButton v-if="membership.pdfUrl" as-child
            ><a :href="membership.pdfUrl">Pobierz PDF karnetu</a></ActionButton
        >
    </section>
    <section class="mt-7 max-w-3xl">
        <h2 class="text-xl font-bold">Historia wejść</h2>
        <p v-if="!entries.data.length" class="mt-3 text-muted-foreground">
            Nie zapisano jeszcze żadnego wejścia.
        </p>
        <ul v-else class="mt-4 space-y-3">
            <li
                v-for="entry in entries.data"
                :key="entry.id"
                class="rounded-lg border p-4"
            >
                {{ orderDate(entry.usedAt) }} · {{ entry.actor }} · jedno
                wejście
            </li>
        </ul>
        <Pagination :links="entries.links" />
    </section>
</template>
