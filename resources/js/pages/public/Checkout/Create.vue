<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import PersonFields from '@/components/checkout/PersonFields.vue';
import InputError from '@/components/InputError.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import { create, store } from '@/routes/checkout';
import { login, register } from '@/routes';
import { pricing, regulations } from '@/routes/public';
import type { CheckoutPerson, CheckoutPlan } from '@/types/checkout';

const props = defineProps<{
    plans: CheckoutPlan[];
    selectedPlanId: number | null;
    checkoutRequestId: string;
    regulation: { id: number; versionNumber: number } | null;
    purchaser: CheckoutPerson;
    paymentAvailable: boolean;
}>();
const page = usePage();
const errorSummary = ref<HTMLElement | null>(null);
const form = useForm({
    checkout_request_id: props.checkoutRequestId,
    membership_plan_id: props.selectedPlanId,
    plan_revision:
        props.plans.find((plan) => plan.id === props.selectedPlanId)
            ?.revision ?? 0,
    for_other_person: false,
    purchaser: { ...props.purchaser },
    holder: { first_name: '', last_name: '', email: '', phone: '' },
    accept_regulations: false,
    accepted_regulation_version_id: props.regulation?.id ?? null,
});
const selected = computed(() =>
    props.plans.find((plan) => plan.id === form.membership_plan_id),
);
watch(selected, (plan) => {
    form.plan_revision = plan?.revision ?? 0;
});
const canSubmit = computed(
    () => !!selected.value && !!props.regulation && props.paymentAvailable,
);
const terms = (plan: CheckoutPlan) =>
    plan.kind === 'entries'
        ? `${plan.includedEntries} wejść · bez ograniczenia czasowego`
        : `Liczba miesięcy: ${plan.durationMonths}`;

function submit() {
    form.transform((data) => {
        const { holder, ...own } = data;
        return data.for_other_person ? { ...own, holder } : own;
    }).post(store.url(), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            errorSummary.value?.focus();
        },
    });
}
</script>

<template>
    <Head title="Kup karnet"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <Link
        :href="pricing()"
        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
        >← Wróć do cennika</Link
    >
    <header class="pt-7 pb-9">
        <p class="text-xs font-bold tracking-widest text-primary uppercase">
            ActiveFit / Karnety online
        </p>
        <h1 class="my-3 text-4xl font-bold tracking-tight sm:text-5xl">
            Kup karnet
        </h1>
        <p class="max-w-2xl text-muted-foreground">
            Wybierz karnet i podaj dane. Po opłaceniu nowy karnet będzie czekał
            na aktywację przy pierwszej wizycie w klubie.
        </p>
    </header>
    <EmptyState
        v-if="!plans.length"
        title="Brak karnetów w sprzedaży online"
        description="Sprawdź ofertę później lub skontaktuj się z klubem."
    />
    <form
        v-else
        class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(290px,.85fr)]"
        @submit.prevent="submit"
    >
        <div class="min-w-0 space-y-5">
            <div
                v-if="form.hasErrors"
                ref="errorSummary"
                tabindex="-1"
                class="rounded-xl border border-destructive p-5 outline-none focus:ring-2 focus:ring-ring"
                role="alert"
            >
                <p class="font-bold">
                    Sprawdź dane przed przejściem do płatności
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </li>
                </ul>
                <Link
                    v-if="
                        form.errors.plan_revision ||
                        form.errors.accepted_regulation_version_id
                    "
                    :href="create({ query: { plan: form.membership_plan_id } })"
                    class="mt-3 inline-block underline"
                    >Wczytaj aktualne warunki zakupu</Link
                >
            </div>
            <fieldset class="checkout-panel" :disabled="form.processing">
                <legend class="sr-only">Wybierz jeden karnet</legend>
                <h2 class="checkout-title">
                    <span class="checkout-step">1</span>Wybierz karnet
                </h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <label
                        v-for="plan in plans"
                        :key="plan.id"
                        class="relative flex min-w-0 cursor-pointer flex-col rounded-xl border-2 p-4 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        :class="
                            selected?.id === plan.id
                                ? 'border-primary bg-secondary'
                                : 'border-border'
                        "
                    >
                        <input
                            v-model="form.membership_plan_id"
                            type="radio"
                            name="membership_plan_id"
                            :value="plan.id"
                            class="mb-3 size-4 accent-primary"
                        />
                        <span class="font-bold break-words">{{
                            plan.name
                        }}</span>
                        <span
                            v-if="plan.description"
                            class="mt-2 text-xs break-words whitespace-pre-line text-muted-foreground"
                            >{{ plan.description }}</span
                        >
                        <strong class="mt-4 text-2xl">{{
                            plan.priceLabel
                        }}</strong>
                        <span class="mt-1 text-xs text-muted-foreground">{{
                            terms(plan)
                        }}</span>
                    </label>
                </div>
                <InputError :message="form.errors.membership_plan_id" />
            </fieldset>
            <section class="checkout-panel" aria-labelledby="buyer-title">
                <h2 id="buyer-title" class="checkout-title">
                    <span class="checkout-step">2</span>Dane kupującego
                </h2>
                <div v-if="!page.props.auth.user" class="mb-6 space-y-3">
                    <p
                        class="rounded-lg bg-secondary p-3 text-sm font-semibold"
                    >
                        Kupujesz bez rejestracji — konto nie jest wymagane.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Masz konto?
                        <Link :href="login()" class="underline"
                            >Zaloguj się</Link
                        >
                        lub
                        <Link :href="register()" class="underline"
                            >zarejestruj się</Link
                        >
                        przed zakupem. Zakupy gościnne pozostają oddzielne od
                        konta.
                    </p>
                </div>
                <p v-else class="mb-5 text-sm text-muted-foreground">
                    Kupujesz na swoim koncie. Dane tego zakupu możesz uzupełnić
                    poniżej.
                </p>
                <PersonFields
                    v-model="form.purchaser"
                    prefix="purchaser"
                    :errors="form.errors"
                    :disabled="form.processing"
                />
            </section>
            <section class="checkout-panel" aria-labelledby="holder-title">
                <h2 id="holder-title" class="checkout-title">
                    <span class="checkout-step">3</span>Dla kogo kupujesz?
                </h2>
                <fieldset
                    class="mb-5 flex flex-wrap gap-3"
                    :disabled="form.processing"
                >
                    <legend class="sr-only">Posiadacz karnetu</legend>
                    <label
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-4 py-3"
                        :class="
                            !form.for_other_person
                                ? 'border-primary bg-secondary'
                                : 'border-border'
                        "
                        ><input
                            v-model="form.for_other_person"
                            type="radio"
                            name="holder_choice"
                            :value="false"
                            class="accent-primary"
                        />Dla siebie</label
                    >
                    <label
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-4 py-3"
                        :class="
                            form.for_other_person
                                ? 'border-primary bg-secondary'
                                : 'border-border'
                        "
                        ><input
                            v-model="form.for_other_person"
                            type="radio"
                            name="holder_choice"
                            :value="true"
                            class="accent-primary"
                        />Dla innej osoby</label
                    >
                </fieldset>
                <PersonFields
                    v-if="form.for_other_person"
                    v-model="form.holder"
                    prefix="holder"
                    :errors="form.errors"
                    :disabled="form.processing"
                />
                <p class="mt-4 rounded-lg bg-secondary p-4 text-sm">
                    {{
                        form.for_other_person
                            ? 'PDF po opłaceniu wyślemy na adres e-mail posiadacza karnetu. Nie musi on mieć konta.'
                            : 'PDF po opłaceniu wyślemy na Twój adres e-mail.'
                    }}
                </p>
            </section>
        </div>
        <aside
            aria-label="Podsumowanie zakupu"
            class="checkout-panel lg:sticky lg:top-6"
        >
            <h2 class="text-lg font-bold">Podsumowanie zakupu</h2>
            <dl class="my-5 divide-y divide-border text-sm">
                <div class="checkout-summary-row">
                    <dt>Karnet</dt>
                    <dd class="font-semibold">{{ selected?.name }}</dd>
                </div>
                <div class="checkout-summary-row">
                    <dt>Posiadacz</dt>
                    <dd>
                        {{ form.for_other_person ? 'Inna osoba' : 'Kupujący' }}
                    </dd>
                </div>
                <div class="checkout-summary-row">
                    <dt>Warunki</dt>
                    <dd>{{ selected && terms(selected) }}</dd>
                </div>
                <div class="checkout-summary-row">
                    <dt>Aktywacja</dt>
                    <dd>Przy pierwszej wizycie</dd>
                </div>
            </dl>
            <p class="mb-6 text-sm text-muted-foreground">
                Opłacenie nie uruchamia ważności. Aktywację wykona pracownik lub
                administrator w klubie.
            </p>
            <label
                v-if="regulation"
                class="mb-2 flex items-start gap-3 text-sm leading-relaxed"
            >
                <input
                    v-model="form.accept_regulations"
                    type="checkbox"
                    required
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.accept_regulations"
                    class="mt-1 size-4 shrink-0 accent-primary"
                />
                <span
                    >Akceptuję
                    <a
                        :href="regulations.url()"
                        target="_blank"
                        rel="noopener"
                        class="underline"
                        >regulamin klubu (wersja {{ regulation.versionNumber }},
                        otwiera się w nowej karcie)</a
                    >
                    i warunki wybranego karnetu. *</span
                >
            </label>
            <InputError :message="form.errors.accept_regulations" />
            <FeedbackMessage
                v-if="!regulation"
                title="Zakup jest chwilowo niedostępny"
                variant="warning"
                ><p>
                    Regulamin zakupu nie został jeszcze opublikowany.
                </p></FeedbackMessage
            >
            <FeedbackMessage
                v-else-if="!paymentAvailable"
                title="Płatności online są chwilowo niedostępne"
                variant="warning"
                ><p>
                    Możesz kupić karnet na recepcji lub spróbować później.
                </p></FeedbackMessage
            >
            <p class="my-6 flex items-baseline justify-between gap-3">
                <span class="font-semibold">Do zapłaty</span
                ><strong class="text-3xl">{{ selected?.priceLabel }}</strong>
            </p>
            <ActionButton
                type="submit"
                class="w-full"
                :loading="form.processing"
                :disabled="!canSubmit"
                loading-text="Przygotowujemy płatność…"
                >Przejdź do płatności →</ActionButton
            >
            <p class="mt-4 text-xs text-muted-foreground">
                Płatność przez Przelewy24. Jedno zamówienie obejmuje jeden
                karnet.
            </p>
        </aside>
    </form>
</template>

<style scoped>
@reference "../../../../css/app.css";
.checkout-panel {
    @apply min-w-0 rounded-2xl border border-border bg-card p-5 sm:p-7;
}
.checkout-title {
    @apply mb-6 flex items-center gap-3 text-xl font-bold;
}
.checkout-step {
    @apply inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary text-sm;
}
.checkout-summary-row {
    @apply flex justify-between gap-5 py-3;
}
.checkout-summary-row dt {
    @apply text-muted-foreground;
}
.checkout-summary-row dd {
    @apply max-w-[65%] text-right break-words;
}
</style>
