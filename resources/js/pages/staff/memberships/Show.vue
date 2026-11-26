<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import Pagination from '@/components/shared/Pagination.vue';
import {
    businessDate,
    orderDate,
    orderTitles,
    paymentMethods,
    salesChannels,
} from '@/lib/orderPresentation';
import {
    index,
    activate,
    redeem,
    confirmPayment,
} from '@/routes/staff/memberships';
import type { CheckoutOrder } from '@/types/checkout';
import type { PaginationLink } from '@/types/pagination';
const props = defineProps<{
    order: CheckoutOrder;
    holder: { name: string; phone: string; email: string };
    canConfirmPayment: boolean;
    membershipId: number | null;
    canActivate: boolean;
    canRedeem: boolean;
    activatedAt: string | null;
    activatedBy: string | null;
    entries: {
        data: { id: number; usedAt: string; actor: string }[];
        links: PaginationLink[];
        total: number;
    } | null;
}>();
const operation = ref<'activate' | 'redeem' | 'payment'>('activate');
const open = ref(false);
const form = useForm({ confirmed: true, request_id: '' });
const title = computed(
    () =>
        ({
            activate: 'Aktywować karnet przy pierwszej wizycie?',
            redeem: 'Odliczyć jedno wejście?',
            payment: 'Potwierdzić otrzymanie opłaty?',
        })[operation.value],
);
const description = computed(
    () =>
        `${props.holder.name} · ${props.order.planName}. ` +
        {
            activate:
                'Ważność rozpocznie się dzisiaj. Aktywacja nie odlicza wejścia.',
            redeem: `Pozostało ${props.order.entriesRemaining} wejść. Zapiszesz wykorzystanie jednego wejścia.`,
            payment: `${props.order.priceLabel} · ${paymentMethods[props.order.paymentMethod]}. Potwierdź wyłącznie pieniądze już otrzymane. Karnet będzie czekał na aktywację.`,
        }[operation.value],
);
function choose(value: typeof operation.value) {
    operation.value = value;
    form.request_id = crypto.randomUUID();
    form.clearErrors();
    open.value = true;
}
function submit() {
    const url =
        operation.value === 'payment'
            ? confirmPayment.url(props.order.reference)
            : operation.value === 'activate'
              ? activate.url(props.membershipId!)
              : redeem.url(props.membershipId!);
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>
<template>
    <Head title="Obsługa karnetu" />
    <Link :href="index()" class="text-sm underline">← Wyniki wyszukiwania</Link>
    <section
        class="mt-6 max-w-3xl rounded-2xl border border-border bg-card p-5 sm:p-7"
    >
        <h1 class="text-3xl font-bold break-words">{{ holder.name }}</h1>
        <p class="mt-3 text-xl font-semibold">{{ order.planName }}</p>
        <p class="mt-3 font-bold">{{ orderTitles[order.state] }}</p>
        <dl class="my-6 grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Telefon</dt>
                <dd>{{ holder.phone }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">E-mail posiadacza</dt>
                <dd class="break-all">{{ holder.email }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Opłata</dt>
                <dd>
                    {{ order.priceLabel }} ·
                    {{ salesChannels[order.salesChannel] }} ·
                    {{ paymentMethods[order.paymentMethod] }}
                </dd>
            </div>
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
            <div v-if="activatedAt">
                <dt class="text-muted-foreground">Aktywacja</dt>
                <dd>{{ orderDate(activatedAt) }} · {{ activatedBy }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">Numer zamówienia</dt>
                <dd class="break-all">{{ order.reference }}</dd>
            </div>
        </dl>
        <p v-if="canActivate" class="mb-4 text-muted-foreground">
            Ważność rozpocznie się przy pierwszej wizycie posiadacza. Aktywacja
            i wykorzystanie wejścia to osobne czynności.
        </p>
        <div class="flex flex-wrap gap-3">
            <ActionButton v-if="canConfirmPayment" @click="choose('payment')"
                >Potwierdź otrzymanie opłaty</ActionButton
            ><ActionButton v-if="canActivate" @click="choose('activate')"
                >Aktywuj karnet</ActionButton
            ><ActionButton v-if="canRedeem" @click="choose('redeem')"
                >Odlicz jedno wejście</ActionButton
            ><ActionButton v-if="order.pdfUrl" as-child variant="outline"
                ><a :href="order.pdfUrl">Pobierz PDF karnetu</a></ActionButton
            >
        </div>
    </section>
    <section v-if="entries" class="mt-7 max-w-3xl">
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
    <ConfirmDialog
        v-model:open="open"
        :title="title"
        :description="description"
        confirm-label="Potwierdź"
        :processing="form.processing"
        :error="Object.values(form.errors)[0]"
        @confirm="submit"
    />
</template>
