<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FormField from '@/components/shared/FormField.vue';
import { businessDate, orderDate, orderTitles } from '@/lib/orderPresentation';
import { dashboard as dashboardRoute } from '@/routes/admin';
import { index as sales } from '@/routes/admin/sales';
import type { CheckoutOrder } from '@/types/checkout';
const props = defineProps<{
    dashboard: {
        today: string;
        from: string;
        to: string;
        activeMemberships: number;
        expiringMemberships: number;
        salesCount: number;
        salesLabel: string;
        recentOrders: {
            reference: string;
            planName: string;
            state: CheckoutOrder['state'];
            priceLabel: string;
            orderedAt: string;
            showUrl: string;
        }[];
    };
}>();
const form = useForm({ from: props.dashboard.from, to: props.dashboard.to });
</script>
<template>
    <Head title="Panel administratora" />
    <header class="mb-7">
        <h1 class="text-3xl font-bold">Panel administratora</h1>
        <p class="mt-3 text-muted-foreground">
            Stan karnetów na {{ businessDate(dashboard.today) }}.
        </p>
    </header>
    <div class="mb-7 grid gap-4 sm:grid-cols-2">
        <section class="rounded-2xl border bg-card p-5">
            <h2 class="font-semibold">Aktywne karnety</h2>
            <p class="my-3 text-4xl font-bold" data-metric="active">
                {{ dashboard.activeMemberships }}
            </p>
            <p class="text-sm text-muted-foreground">
                Ważne dzisiaj, z dostępnym limitem wejść lub bez limitu.
            </p>
        </section>
        <section class="rounded-2xl border bg-card p-5">
            <h2 class="font-semibold">Kończące ważność</h2>
            <p class="my-3 text-4xl font-bold" data-metric="expiring">
                {{ dashboard.expiringMemberships }}
            </p>
            <p class="text-sm text-muted-foreground">
                Aktywne karnety z końcem ważności dziś lub w ciągu najbliższych
                7 dni.
            </p>
        </section>
    </div>
    <section class="mb-7 rounded-2xl border bg-card p-5 sm:p-6">
        <h2 class="text-xl font-bold">Sprzedaż w wybranym okresie</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            Według daty potwierdzonej opłaty, w Europe/Warsaw. Uwzględnia
            sprzedaż online i recepcyjną.
        </p>
        <form
            class="my-5 grid gap-4 sm:grid-cols-3"
            @submit.prevent="
                form.get(dashboardRoute.url(), { preserveScroll: true })
            "
        >
            <FormField
                id="sales-from"
                v-model="form.from"
                type="date"
                label="Od"
                required
                :error="form.errors.from"
            /><FormField
                id="sales-to"
                v-model="form.to"
                type="date"
                label="Do (włącznie)"
                required
                :error="form.errors.to"
            /><ActionButton
                type="submit"
                class="self-end"
                :loading="form.processing"
                >Pokaż okres</ActionButton
            >
        </form>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <p class="text-sm text-muted-foreground">Potwierdzone zakupy</p>
                <p class="mt-2 text-3xl font-bold" data-metric="sales-count">
                    {{ dashboard.salesCount }}
                </p>
            </div>
            <div>
                <p class="text-sm text-muted-foreground">Suma opłat</p>
                <p
                    class="mt-2 text-3xl font-bold break-words"
                    data-metric="sales-total"
                >
                    {{ dashboard.salesLabel }}
                </p>
            </div>
        </div>
    </section>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-bold">Najnowsze zamówienia</h2>
        <ActionButton as-child variant="outline"
            ><Link :href="sales()">Przegląd sprzedaży</Link></ActionButton
        >
    </div>
    <p class="mb-4 text-sm text-muted-foreground">
        Ostatnie 10 zakupów, wszystkie stany. Lista jest niezależna od wybranego
        okresu opłat.
    </p>
    <div v-if="dashboard.recentOrders.length" class="space-y-4">
        <article
            v-for="order in dashboard.recentOrders"
            :key="order.reference"
            class="min-w-0 rounded-2xl border bg-card p-5"
        >
            <div class="flex flex-wrap justify-between gap-3">
                <h3 class="font-bold break-words">{{ order.planName }}</h3>
                <strong>{{ order.priceLabel }}</strong>
            </div>
            <p class="mt-3">{{ orderTitles[order.state] }}</p>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ orderDate(order.orderedAt) }}
            </p>
            <ActionButton as-child variant="outline" class="mt-4"
                ><Link :href="order.showUrl"
                    >Szczegóły sprzedaży</Link
                ></ActionButton
            >
        </article>
    </div>
    <EmptyState
        v-else
        title="Brak zamówień"
        description="Nowe zakupy pojawią się tutaj po zapisaniu zamówienia."
    />
</template>
