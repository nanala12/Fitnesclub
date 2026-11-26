<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { index, resend } from '@/routes/admin/deliveries';
import { show as sale } from '@/routes/admin/sales';
import type { PaginationLink } from '@/types/pagination';

type Delivery = {
    id: number;
    planName: string;
    orderReference: string;
    recipientLabel: string;
    status: 'pending' | 'processing' | 'sent' | 'failed';
    attemptsCount: number;
    maxAttempts: number;
    needsReview: boolean;
    errorLabel: string | null;
    scheduledFor: string;
    sentAt: string | null;
    canResend: boolean;
    isResend: boolean;
    attempts: { number: number; status: string; attemptedAt: string }[];
};
defineProps<{
    orderReference: string | null;
    deliveries: { data: Delivery[]; links: PaginationLink[]; total: number };
}>();
const labels = {
    pending: 'Oczekuje na wysyłkę',
    processing: 'Wysyłanie',
    sent: 'Przekazano do serwera poczty',
    failed: 'Wysyłka nieudana',
};
const selected = ref<Delivery | null>(null);
const open = ref(false);
const form = useForm({ request_id: '', confirm_resend: true });
const date = (value: string) =>
    new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: 'Europe/Warsaw',
        hourCycle: 'h23',
    }).format(new Date(value));
function choose(delivery: Delivery) {
    selected.value = delivery;
    form.request_id = crypto.randomUUID();
    form.clearErrors();
    open.value = true;
}
function submit() {
    if (!selected.value) return;
    form.post(resend.url(selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>
<template>
    <Head title="Wysyłki PDF karnetów" />
    <p v-if="orderReference" class="mb-5 text-sm break-all">
        Wysyłki dla zakupu {{ orderReference }} ·
        <Link :href="sale(orderReference)" class="underline"
            >Wróć do szczegółów sprzedaży</Link
        >
    </p>
    <header class="mb-7 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold">Wysyłki PDF karnetów</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Zleceń: {{ deliveries.total }}. Błąd wiadomości nie zmienia
                opłaty ani karnetu.
            </p>
        </div>
        <ActionButton as-child variant="outline"
            ><a :href="index.url()">Odśwież stan</a></ActionButton
        >
    </header>
    <FeedbackMessage title="Ponowienie na zapisany adres" class="mb-6"
        ><p>
            Wiadomość trafi do odbiorcy zapisanego przy zakupie. Przy niepewnym
            wyniku najpierw sprawdź, czy dotarła. Ponowna wysyłka może
            dostarczyć drugi egzemplarz wiadomości.
        </p></FeedbackMessage
    >
    <div v-if="deliveries.data.length" class="space-y-4">
        <article
            v-for="delivery in deliveries.data"
            :key="delivery.id"
            class="min-w-0 rounded-2xl border border-border bg-card p-5 sm:p-6"
        >
            <div class="flex flex-wrap justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-xl font-bold break-words">
                        {{ delivery.planName }}
                    </h2>
                    <p class="mt-2 text-sm break-all text-muted-foreground">
                        Zamówienie {{ delivery.orderReference }} · Wysyłka #{{
                            delivery.id
                        }}
                    </p>
                </div>
                <span
                    class="h-fit rounded-full bg-secondary px-3 py-1 text-sm font-semibold"
                    >{{
                        delivery.needsReview
                            ? 'Wymaga sprawdzenia'
                            : labels[delivery.status]
                    }}</span
                >
            </div>
            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Odbiorca</dt>
                    <dd class="break-all">{{ delivery.recipientLabel }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Próby</dt>
                    <dd>
                        {{ delivery.attemptsCount }} / {{ delivery.maxAttempts
                        }}{{ delivery.isResend ? ' · Ponowna wysyłka' : '' }}
                    </dd>
                </div>
                <div v-if="delivery.sentAt">
                    <dt class="text-muted-foreground">Przekazano do poczty</dt>
                    <dd>{{ date(delivery.sentAt) }}</dd>
                </div>
                <div
                    v-else-if="
                        !delivery.needsReview &&
                        delivery.attemptsCount < delivery.maxAttempts &&
                        delivery.status !== 'processing'
                    "
                >
                    <dt class="text-muted-foreground">Zaplanowana próba</dt>
                    <dd>{{ date(delivery.scheduledFor) }}</dd>
                </div>
            </dl>
            <FeedbackMessage
                v-if="delivery.errorLabel"
                :title="
                    delivery.needsReview
                        ? 'Sprawdź wynik poprzedniej próby'
                        : 'Problem z dostarczeniem'
                "
                variant="warning"
                class="mt-4"
                ><p>{{ delivery.errorLabel }}</p></FeedbackMessage
            >
            <details v-if="delivery.attempts.length" class="mt-4 text-sm">
                <summary class="min-h-11 cursor-pointer py-3 font-semibold">
                    Historia prób
                </summary>
                <ul class="space-y-2">
                    <li
                        v-for="attempt in delivery.attempts"
                        :key="attempt.number"
                    >
                        Próba {{ attempt.number }} —
                        {{
                            attempt.status === 'sent'
                                ? 'Przekazano do poczty'
                                : 'Nieudana'
                        }}
                        · {{ date(attempt.attemptedAt) }}
                    </li>
                </ul>
            </details>
            <ActionButton
                v-if="delivery.canResend"
                class="mt-4"
                variant="outline"
                @click="choose(delivery)"
                >Wyślij ponownie</ActionButton
            >
            <p v-else class="mt-4 text-sm text-muted-foreground">
                Wysyłka lub automatyczne ponowienie jest w toku.
            </p>
        </article>
        <Pagination :links="deliveries.links" />
    </div>
    <EmptyState
        v-else
        title="Brak zleceń PDF"
        description="Zlecenia pojawią się po potwierdzeniu opłaty za karnet."
    />
    <ConfirmDialog
        v-model:open="open"
        title="Wysłać PDF ponownie?"
        :description="
            'PDF trafi na zapisany adres: ' +
            (selected?.recipientLabel ?? '') +
            '. Upewnij się, czy poprzednia wiadomość nie dotarła. Ta operacja nie zmienia płatności ani karnetu.'
        "
        confirm-label="Potwierdź wysyłkę"
        :processing="form.processing"
        :error="Object.values(form.errors)[0]"
        @confirm="submit"
    />
</template>
