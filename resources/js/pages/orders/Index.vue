<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import {
    orderDate,
    orderTitles,
    paymentMethods,
    salesChannels,
} from '@/lib/orderPresentation';
import { create } from '@/routes/checkout';
import type { CheckoutOrder } from '@/types/checkout';
import type { PaginationLink } from '@/types/pagination';
defineProps<{
    orders: { data: CheckoutOrder[]; links: PaginationLink[]; total: number };
}>();
</script>
<template>
    <Head title="Moje zamówienia"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <header class="mb-7 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold">Moje zamówienia</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Twoje zakupy, również dla innych osób i po zakończeniu ważności
                karnetu. Zakupy gościnne pozostają oddzielne od konta.
            </p>
        </div>
        <ActionButton as-child
            ><Link :href="create()">Kup karnet</Link></ActionButton
        >
    </header>
    <div v-if="orders.data.length" class="space-y-4">
        <article
            v-for="order in orders.data"
            :key="order.reference"
            class="min-w-0 rounded-2xl border border-border bg-card p-5 sm:p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <h2 class="text-xl font-bold break-words">
                    {{ order.planName }}
                </h2>
                <p class="text-lg font-bold">{{ order.priceLabel }}</p>
            </div>
            <p class="mt-3 font-semibold">{{ orderTitles[order.state] }}</p>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Złożono</dt>
                    <dd>{{ orderDate(order.orderedAt) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Płatność</dt>
                    <dd>
                        {{ salesChannels[order.salesChannel] }} ·
                        {{ paymentMethods[order.paymentMethod] }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground">Numer zamówienia</dt>
                    <dd class="break-all">{{ order.reference }}</dd>
                </div>
            </dl>
            <ActionButton as-child variant="outline" class="mt-5"
                ><Link :href="order.showUrl"
                    >Szczegóły zamówienia</Link
                ></ActionButton
            >
        </article>
        <Pagination :links="orders.links" />
    </div>
    <EmptyState
        v-else
        title="Nie masz jeszcze zamówień"
        description="Zakupy wykonane z Twojego konta pojawią się tutaj. Możesz kupić karnet dla siebie lub innej osoby."
    />
</template>
