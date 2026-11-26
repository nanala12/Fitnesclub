<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ActionButton from '@/components/shared/ActionButton.vue';
import {
    businessDate,
    orderDate,
    orderTitles,
    paymentMethods,
    salesChannels,
} from '@/lib/orderPresentation';
import { index } from '@/routes/admin/sales';
import type { CheckoutOrder } from '@/types/checkout';
import type { PaymentMethod } from '@/types/admin-sales';
type Person = {
    name: string;
    phone: string;
    email: string;
    historyUrl: string | null;
};
defineProps<{
    order: CheckoutOrder;
    holder: Person;
    purchaser: Person;
    createdBy: string | null;
    payments: {
        id: number;
        status: string;
        method: PaymentMethod;
        amountLabel: string;
        confirmedAt: string | null;
        confirmedBy: string | null;
    }[];
    auditUrl: string;
    deliveriesUrl: string;
    receptionUrl: string;
    renewalHistoryUrl: string | null;
}>();
const statuses: Record<string, string> = {
    initiated: 'Przygotowywanie',
    pending: 'Oczekuje',
    unknown: 'Wymaga sprawdzenia',
    confirmed: 'Potwierdzona',
    failed: 'Nieudana',
    cancelled: 'Anulowana',
};
</script>
<template>
    <Head title="Szczegóły sprzedaży" /><Link
        :href="index()"
        class="text-sm underline"
        >← Sprzedaż i karnety</Link
    >
    <section class="mt-6 rounded-2xl border bg-card p-5 sm:p-7">
        <h1 class="text-3xl font-bold break-words">{{ order.planName }}</h1>
        <p class="mt-3 font-semibold">{{ orderTitles[order.state] }}</p>
        <p class="mt-3">
            {{ order.priceLabel }} · {{ salesChannels[order.salesChannel] }} ·
            {{ paymentMethods[order.paymentMethod] }}
        </p>
        <p class="mt-3 text-sm">
            Złożono: {{ orderDate(order.orderedAt) }} ·
            {{
                createdBy ? `Sprzedaż zapisał(a): ${createdBy}` : 'Zakup online'
            }}
        </p>
        <p class="mt-2 text-xs break-all">{{ order.reference }}</p>
        <dl class="my-5 grid gap-4 sm:grid-cols-2">
            <div v-if="order.startsOn">
                <dt class="text-muted-foreground">Ważny od</dt>
                <dd>{{ businessDate(order.startsOn) }}</dd>
            </div>
            <div v-if="order.expiresOn">
                <dt class="text-muted-foreground">Ważny do (włącznie)</dt>
                <dd>{{ businessDate(order.expiresOn) }}</dd>
            </div>
            <div v-if="order.entriesRemaining !== null">
                <dt class="text-muted-foreground">Pozostałe wejścia</dt>
                <dd>
                    {{ order.entriesRemaining }} · bez ograniczenia czasowego
                </dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-3">
            <ActionButton as-child variant="outline"
                ><Link :href="receptionUrl"
                    >Obsługa karnetu i wejścia</Link
                ></ActionButton
            ><ActionButton as-child variant="outline"
                ><Link :href="auditUrl"
                    >Historia operacji tego zakupu</Link
                ></ActionButton
            ><ActionButton as-child variant="outline"
                ><Link :href="deliveriesUrl"
                    >Wysyłki PDF tego zakupu</Link
                ></ActionButton
            ><ActionButton v-if="renewalHistoryUrl" as-child variant="outline"
                ><Link :href="renewalHistoryUrl"
                    >Historia karnetu i przedłużeń</Link
                ></ActionButton
            >
        </div>
    </section>
    <div class="my-6 grid gap-4 sm:grid-cols-2">
        <section
            v-for="(person, label) in {
                Posiadacz: holder,
                Kupujący: purchaser,
            }"
            :key="label"
            class="min-w-0 rounded-2xl border bg-card p-5"
        >
            <h2 class="text-xl font-bold">{{ label }}</h2>
            <p class="mt-3 break-words">{{ person.name }}</p>
            <p class="mt-2 break-all">{{ person.phone }}</p>
            <p class="mt-2 break-all">{{ person.email }}</p>
            <ActionButton
                v-if="person.historyUrl"
                as-child
                variant="outline"
                class="mt-4"
                ><Link :href="person.historyUrl"
                    >Historia konta</Link
                ></ActionButton
            >
            <p v-else class="mt-4 text-sm text-muted-foreground">
                Brak powiązania tej osoby z kontem. Dane pozostają częścią tego
                zakupu.
            </p>
        </section>
    </div>
    <h2 class="text-xl font-bold">Próby płatności</h2>
    <p v-if="!payments.length" class="mt-3 text-muted-foreground">
        Brak zapisanych płatności.
    </p>
    <ol v-else class="mt-4 space-y-4">
        <li
            v-for="payment in payments"
            :key="payment.id"
            class="rounded-2xl border bg-card p-5"
        >
            <p class="font-semibold">
                Płatność #{{ payment.id }} · {{ statuses[payment.status] }}
            </p>
            <p class="mt-2">
                {{ payment.amountLabel }} · {{ paymentMethods[payment.method] }}
            </p>
            <p v-if="payment.confirmedAt" class="mt-2 text-sm">
                Potwierdzono: {{ orderDate(payment.confirmedAt) }} ·
                {{ payment.confirmedBy ?? 'Zweryfikowano przez Przelewy24' }}
            </p>
        </li>
    </ol>
</template>
