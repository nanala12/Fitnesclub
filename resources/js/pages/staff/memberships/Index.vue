<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FormField from '@/components/shared/FormField.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { orderTitles, orderDate } from '@/lib/orderPresentation';
import { create, search } from '@/routes/staff/memberships';
import type { CheckoutOrder } from '@/types/checkout';
import type { PaginationLink } from '@/types/pagination';
const props = defineProps<{
    phone: string | null;
    accounts: { id: number; name: string; saleUrl: string }[];
    results: {
        data: (CheckoutOrder & { holderName: string })[];
        links: PaginationLink[];
        total: number;
    };
}>();
const form = useForm({ phone: props.phone ?? '' });
function submit() {
    form.post(search.url());
}
</script>
<template>
    <Head title="Obsługa karnetów" />
    <header class="mb-7 flex flex-wrap justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold">Obsługa karnetów</h1>
            <p class="mt-2 text-muted-foreground">
                Wyszukaj posiadacza i wybierz właściwy zakup.
            </p>
        </div>
        <ActionButton as-child
            ><Link :href="create()">Sprzedaż w recepcji</Link></ActionButton
        >
    </header>
    <form class="mb-7 max-w-xl space-y-3" @submit.prevent="submit">
        <FormField
            id="holder-search"
            v-model="form.phone"
            type="tel"
            label="Telefon posiadacza"
            hint="Pełny numer, np. +48 600 111 222. Wspólny numer może należeć do kilku osób."
            :error="form.errors.phone"
            :disabled="form.processing"
        />
        <ActionButton type="submit" :loading="form.processing"
            >Szukaj</ActionButton
        >
        <ActionButton
            v-if="phone"
            variant="outline"
            :disabled="form.processing"
            @click="
                form.phone = '';
                submit();
            "
            >Wyczyść</ActionButton
        >
    </form>
    <section v-if="accounts.length" class="mb-6 rounded-2xl border bg-card p-5">
        <h2 class="text-xl font-bold">Zarejestrowane konto</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            Wybór dotyczy nowego zakupu. Wcześniejsze zakupy gościnne pozostają
            oddzielne.
        </p>
        <div v-for="account in accounts" :key="account.id" class="mt-4">
            <p>{{ account.name }}</p>
            <ActionButton as-child variant="outline" class="mt-2"
                ><Link :href="account.saleUrl"
                    >Sprzedaż dla tego konta</Link
                ></ActionButton
            >
        </div>
    </section>
    <div v-if="results.data.length" class="space-y-4">
        <p class="text-sm text-muted-foreground">
            Znalezionych zakupów: {{ results.total }}. Sprawdź imię i nazwisko —
            nie łączymy osób po numerze telefonu.
        </p>
        <article
            v-for="order in results.data"
            :key="order.reference"
            class="rounded-2xl border border-border bg-card p-5"
        >
            <h2 class="text-xl font-bold break-words">
                {{ order.holderName }}
            </h2>
            <p class="mt-2 font-semibold">
                {{ order.planName }} · {{ order.priceLabel }}
            </p>
            <p class="mt-2">{{ orderTitles[order.state] }}</p>
            <p class="mt-2 text-sm text-muted-foreground">
                Zakup: {{ orderDate(order.orderedAt) }}
            </p>
            <p class="mt-2 text-xs break-all text-muted-foreground">
                {{ order.reference }}
            </p>
            <ActionButton as-child variant="outline" class="mt-4"
                ><Link :href="order.showUrl"
                    >Szczegóły i obsługa</Link
                ></ActionButton
            >
        </article>
        <Pagination :links="results.links" />
    </div>
    <EmptyState
        v-else
        :title="phone ? 'Nie znaleziono zakupów' : 'Wyszukaj posiadacza'"
        :description="
            phone
                ? 'Sprawdź numer telefonu lub rozpocznij sprzedaż w recepcji.'
                : 'Podaj numer telefonu, aby zobaczyć karnety i zamówienia przypisane do posiadaczy.'
        "
    />
</template>
