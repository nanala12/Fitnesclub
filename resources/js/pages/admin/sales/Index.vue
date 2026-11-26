<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FormField from '@/components/shared/FormField.vue';
import Pagination from '@/components/shared/Pagination.vue';
import {
    orderDate,
    orderTitles,
    paymentMethods,
    salesChannels,
} from '@/lib/orderPresentation';
import { index, search as searchRoute } from '@/routes/admin/sales';
import type { SalesFilters, SalesOrder } from '@/types/admin-sales';
import type { PaginationLink } from '@/types/pagination';
const props = defineProps<{
    filters: SalesFilters;
    search: { phone?: string; reference?: string; person?: string };
    orders: { data: SalesOrder[]; links: PaginationLink[]; total: number };
}>();
const filtersForm = useForm({
    channel: props.filters.channel ?? '',
    method: props.filters.method ?? '',
    status: props.filters.status ?? '',
});
const searchForm = useForm({
    phone: props.search.phone ?? '',
    reference: props.search.reference ?? '',
    person: props.search.person ?? 'holder',
});
function filter() {
    router.get(
        index.url(),
        { ...props.filters, ...filtersForm.data(), page: 1 },
        { preserveState: true },
    );
}
function submitSearch() {
    searchForm.post(searchRoute.url(), {
        onSuccess: () => {
            filtersForm.channel = '';
            filtersForm.method = '';
            filtersForm.status = '';
        },
    });
}
function reset() {
    searchForm.phone = '';
    searchForm.reference = '';
    submitSearch();
}
</script>
<template>
    <Head title="Sprzedaż i karnety" />
    <h1 class="text-3xl font-bold">Sprzedaż i karnety</h1>
    <p class="mt-3 text-muted-foreground">
        Zamówienia, płatności i historia obsługi. Kupujący i posiadacz mogą być
        różnymi osobami.
    </p>
    <p
        v-if="
            filters.holder_account || filters.purchaser_account || filters.root
        "
        class="mt-4 font-semibold"
    >
        {{
            filters.root
                ? 'Powiązane przedłużenia'
                : filters.holder_account
                  ? 'Historia konta posiadacza'
                  : 'Historia konta kupującego'
        }}
    </p>
    <form
        class="mt-6 grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-3"
        @submit.prevent="filter"
    >
        <div>
            <label for="channel" class="block text-sm font-semibold"
                >Kanał sprzedaży</label
            ><select
                id="channel"
                v-model="filtersForm.channel"
                class="mt-2 min-h-11 w-full rounded-lg border p-2"
            >
                <option value="">Wszystkie</option>
                <option value="online">Online</option>
                <option value="reception">Recepcja</option>
            </select>
        </div>
        <div>
            <label for="method" class="block text-sm font-semibold"
                >Metoda płatności</label
            ><select
                id="method"
                v-model="filtersForm.method"
                class="mt-2 min-h-11 w-full rounded-lg border p-2"
            >
                <option value="">Wszystkie</option>
                <option value="przelewy24">Przelewy24</option>
                <option value="cash">Gotówka</option>
                <option value="card">Karta w recepcji</option>
            </select>
        </div>
        <div>
            <label for="status" class="block text-sm font-semibold"
                >Stan zamówienia</label
            ><select
                id="status"
                v-model="filtersForm.status"
                class="mt-2 min-h-11 w-full rounded-lg border p-2"
            >
                <option value="">Wszystkie</option>
                <option value="pending">Oczekujące</option>
                <option value="paid">Opłacone</option>
                <option value="failed">Nieudane</option>
                <option value="cancelled">Anulowane</option>
            </select>
        </div>
        <ActionButton type="submit">Zastosuj filtry</ActionButton>
    </form>
    <form
        class="my-6 grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-2"
        @submit.prevent="submitSearch"
    >
        <div>
            <label for="person" class="block text-sm font-semibold"
                >Wyszukiwanie telefonu</label
            ><select
                id="person"
                v-model="searchForm.person"
                class="mt-2 min-h-11 w-full rounded-lg border p-2"
            >
                <option value="holder">Posiadacz</option>
                <option value="purchaser">Kupujący</option>
            </select>
        </div>
        <FormField
            id="sales-phone"
            v-model="searchForm.phone"
            label="Numer telefonu"
            type="tel"
            hint="Np. +48 600 111 222"
            :error="searchForm.errors.phone"
        />
        <FormField
            id="sales-reference"
            v-model="searchForm.reference"
            label="Numer zamówienia"
            :error="searchForm.errors.reference"
        />
        <div class="flex flex-wrap items-end gap-3">
            <ActionButton type="submit" :loading="searchForm.processing"
                >Szukaj</ActionButton
            ><ActionButton
                variant="outline"
                :disabled="searchForm.processing"
                @click="reset"
                >Wyczyść wyszukiwanie i filtry</ActionButton
            >
        </div>
    </form>
    <p class="mb-4 text-sm text-muted-foreground">
        Zamówień: {{ orders.total }}. Wspólny kontakt nie łączy posiadaczy ani
        zakupów gościnnych.
    </p>
    <div v-if="orders.data.length" class="space-y-4">
        <article
            v-for="order in orders.data"
            :key="order.reference"
            class="min-w-0 rounded-2xl border bg-card p-5"
        >
            <div class="flex flex-wrap justify-between gap-3">
                <h2 class="text-xl font-bold break-words">
                    {{ order.planName }}
                </h2>
                <strong>{{ order.priceLabel }}</strong>
            </div>
            <p class="mt-3">Posiadacz: {{ order.holderName }}</p>
            <p class="mt-1">Kupujący: {{ order.purchaserName }}</p>
            <p class="mt-3 font-semibold">{{ orderTitles[order.state] }}</p>
            <p class="mt-2 text-sm">
                {{ salesChannels[order.salesChannel] }} ·
                {{ paymentMethods[order.paymentMethod] }} ·
                {{ orderDate(order.orderedAt) }}
            </p>
            <p class="mt-2 text-xs break-all text-muted-foreground">
                {{ order.reference }}
            </p>
            <ActionButton as-child variant="outline" class="mt-4"
                ><Link :href="order.showUrl"
                    >Szczegóły sprzedaży</Link
                ></ActionButton
            >
        </article>
        <Pagination :links="orders.links" />
    </div>
    <EmptyState
        v-else
        title="Brak zamówień"
        description="Nie znaleziono sprzedaży spełniającej wybrane warunki."
    />
</template>
