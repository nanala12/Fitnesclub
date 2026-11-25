<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import { Button } from '@/components/ui/button';
import { create, pay } from '@/routes/checkout';
import { index as history } from '@/routes/orders';
import {
    businessDate,
    orderDate,
    orderTitles as titles,
    salesChannels,
    paymentMethods,
} from '@/lib/orderPresentation';
import type { CheckoutOrder } from '@/types/checkout';

const props = defineProps<{ order: CheckoutOrder; notice: string | null }>();
const form = useForm({});
const refreshing = ref(false);
function refresh() {
    refreshing.value = true;
    router.get(
        props.order.showUrl,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="titles[order.state]"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <div
        class="mx-auto max-w-2xl rounded-2xl border border-border bg-card p-6 sm:p-9"
    >
        <p class="text-xs font-bold tracking-widest text-primary uppercase">
            ActiveFit / Twój zakup
        </p>
        <h1 class="mt-3 text-3xl font-bold tracking-tight">
            {{ titles[order.state] }}
        </h1>
        <p
            v-if="order.state === 'pending_activation'"
            class="mt-5 text-muted-foreground"
        >
            Nowy karnet aktywuje pracownik lub administrator przy Twojej
            pierwszej wizycie w klubie. Wtedy rozpocznie się jego ważność. PDF
            po opłaceniu otrzyma właściwy posiadacz na adres podany przy
            zakupie.
        </p>
        <p
            v-else-if="order.state === 'checking_payment'"
            class="mt-5 text-muted-foreground"
        >
            Czekamy na potwierdzenie wyniku płatności. Nie składaj kolejnego
            zamówienia na ten sam karnet. Odśwież stan za chwilę. Jeśli się nie
            zmieni, skontaktuj się z klubem i podaj numer zamówienia.
        </p>
        <p
            v-else-if="order.state === 'awaiting_payment'"
            class="mt-5 text-muted-foreground"
        >
            Nie otrzymaliśmy jeszcze potwierdzenia opłaty. Jeśli płatność
            została wykonana, odśwież stan za chwilę. Sam powrót ze strony
            operatora nie potwierdza zakupu.
        </p>
        <p
            v-else-if="order.state === 'scheduled'"
            class="mt-5 text-muted-foreground"
        >
            Przedłużenie rozpocznie się w podanym terminie, bez kolejnej
            aktywacji w recepcji.
        </p>
        <p
            v-else-if="order.state === 'active'"
            class="mt-5 text-muted-foreground"
        >
            Karnet jest dostępny. Każde wykorzystane wejście pracownik zapisuje
            osobno.
        </p>
        <p
            v-else-if="order.state === 'expired' || order.state === 'exhausted'"
            class="mt-5 text-muted-foreground"
        >
            Zakup pozostaje w historii. Zakończenie ważności lub przeniesienie
            wejść nie usuwa zamówienia.
        </p>
        <p
            v-else-if="order.state === 'awaiting_reception'"
            class="mt-5 text-muted-foreground"
        >
            Opłatę gotówką lub kartą potwierdza pracownik recepcji. Karnet
            zostanie wydany po tym potwierdzeniu.
        </p>
        <p v-else class="mt-5 text-muted-foreground">
            Nie mamy potwierdzonej opłaty za to zamówienie. Jeśli przycisk
            płatności jest dostępny, możesz wrócić do tego samego zakupu. Jeśli
            pieniądze zostały pobrane lub nie możesz kontynuować, skontaktuj się
            z klubem i podaj numer zamówienia.
        </p>
        <FeedbackMessage
            v-if="notice"
            title="Informacja o płatności"
            variant="warning"
            class="mt-6"
            ><p>{{ notice }}</p></FeedbackMessage
        >
        <FeedbackMessage
            v-if="
                !order.paymentAvailable &&
                !order.paid &&
                order.salesChannel === 'online'
            "
            title="Płatności online są chwilowo niedostępne"
            variant="warning"
            class="mt-6"
            ><p>
                Zamówienie zostało zachowane. Spróbuj później lub skontaktuj się
                z klubem.
            </p></FeedbackMessage
        >
        <dl class="my-7 space-y-4 border-y border-border py-5 text-sm">
            <div class="flex justify-between gap-5">
                <dt class="text-muted-foreground">Karnet</dt>
                <dd class="text-right font-semibold break-words">
                    {{ order.planName }}
                </dd>
            </div>
            <div class="flex justify-between gap-5">
                <dt class="text-muted-foreground">Kwota</dt>
                <dd class="font-bold">{{ order.priceLabel }}</dd>
            </div>
            <div class="flex justify-between gap-5">
                <dt class="text-muted-foreground">Kanał sprzedaży</dt>
                <dd>{{ salesChannels[order.salesChannel] }}</dd>
            </div>
            <div class="flex justify-between gap-5">
                <dt class="text-muted-foreground">Metoda płatności</dt>
                <dd>{{ paymentMethods[order.paymentMethod] }}</dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-muted-foreground">Złożono</dt>
                <dd>{{ orderDate(order.orderedAt) }}</dd>
            </div>
            <div
                v-if="order.paidAt"
                class="flex flex-wrap justify-between gap-2"
            >
                <dt class="text-muted-foreground">Opłacono</dt>
                <dd>{{ orderDate(order.paidAt) }}</dd>
            </div>
            <div
                v-if="order.startsOn"
                class="flex flex-wrap justify-between gap-2"
            >
                <dt class="text-muted-foreground">Ważny od</dt>
                <dd>{{ businessDate(order.startsOn) }}</dd>
            </div>
            <div
                v-if="order.expiresOn"
                class="flex flex-wrap justify-between gap-2"
            >
                <dt class="text-muted-foreground">Ważny do (włącznie)</dt>
                <dd>{{ businessDate(order.expiresOn) }}</dd>
            </div>
            <div
                v-if="order.entriesRemaining !== null"
                class="flex justify-between gap-5"
            >
                <dt class="text-muted-foreground">Pozostałe wejścia</dt>
                <dd>{{ order.entriesRemaining }}</dd>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-muted-foreground">Numer zamówienia</dt>
                <dd class="break-all">{{ order.reference }}</dd>
            </div>
        </dl>
        <Button v-if="order.pdfUrl" as-child class="min-h-11 w-full"
            ><a :href="order.pdfUrl">Pobierz PDF karnetu</a></Button
        >
        <p
            v-if="Object.keys(form.errors).length"
            role="alert"
            class="mt-4 text-sm text-destructive"
        >
            {{ Object.values(form.errors)[0] }}
        </p>
        <form
            v-if="order.canPay"
            @submit.prevent="form.post(pay.url(order.reference))"
        >
            <ActionButton
                type="submit"
                class="w-full"
                :loading="form.processing"
                loading-text="Przygotowujemy płatność…"
                >Przejdź do płatności →</ActionButton
            >
        </form>
        <ActionButton
            variant="outline"
            class="mt-3 w-full"
            :loading="refreshing"
            :disabled="form.processing"
            @click="refresh"
            >Odśwież stan zamówienia</ActionButton
        >
        <p v-if="order.isGuest" class="mt-5 text-xs text-muted-foreground">
            Dostęp w tej przeglądarce jest dostępny przez ograniczony czas.
            Zakup gościnny pozostaje oddzielny od konta.
        </p>
        <Link
            v-if="!order.isGuest"
            :href="history()"
            class="mt-6 mr-6 inline-block text-sm underline"
            >Moje zamówienia</Link
        >
        <Link :href="create()" class="mt-6 inline-block text-sm underline"
            >Kup kolejny karnet</Link
        >
    </div>
</template>
