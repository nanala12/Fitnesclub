<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PersonFields from '@/components/checkout/PersonFields.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import type { CheckoutPerson } from '@/types/checkout';
import { index, store } from '@/routes/staff/memberships';
const props = defineProps<{
    requestId: string;
    account: (CheckoutPerson & { id: number; name: string }) | null;
    plans: {
        id: number;
        revision: number;
        name: string;
        priceLabel: string;
        kind: string;
        includedEntries: number | null;
        durationMonths: number | null;
    }[];
}>();
const form = useForm({
    request_id: props.requestId,
    purchaser_user_id: props.account?.id ?? null,
    membership_plan_id: props.plans[0]?.id ?? null,
    plan_revision: props.plans[0]?.revision ?? 0,
    payment_method: 'cash',
    for_other_person: false,
    purchaser: {
        first_name: props.account?.first_name ?? '',
        last_name: props.account?.last_name ?? '',
        email: props.account?.email ?? '',
        phone: props.account?.phone ?? '',
    },
    holder: { first_name: '', last_name: '', email: '', phone: '' },
});
const selected = computed(() =>
    props.plans.find((plan) => plan.id === Number(form.membership_plan_id)),
);
const open = ref(false);
function save() {
    form.plan_revision = selected.value?.revision ?? 0;
    form.transform(({ holder, ...data }) =>
        data.for_other_person ? { ...data, holder } : data,
    ).post(store.url(), {
        onSuccess: () => {
            open.value = false;
        },
        onError: () => {
            open.value = false;
        },
    });
}
</script>
<template>
    <Head title="Sprzedaż w recepcji" />
    <Link :href="index()" class="text-sm underline">← Obsługa karnetów</Link>
    <h1 class="mt-5 text-3xl font-bold">Sprzedaż w recepcji</h1>
    <p class="mt-3 max-w-2xl text-muted-foreground">
        Zapisz dane kupującego i posiadacza. Konto nie jest wymagane. Na
        następnym ekranie osobno potwierdzisz otrzymanie opłaty.
    </p>
    <p v-if="account" class="mt-4 font-semibold">
        Nowy zakup przypiszesz do konta: {{ account.name }}. Nie zmienia to
        zakupów gościnnych.
    </p>
    <EmptyState
        v-if="!plans.length"
        class="mt-6"
        title="Brak dostępnych karnetów"
        description="Administrator musi dodać lub udostępnić plan do sprzedaży."
    />
    <form v-else class="mt-7 max-w-3xl space-y-7" @submit.prevent="open = true">
        <p
            v-if="Object.keys(form.errors).length"
            role="alert"
            class="text-destructive"
        >
            {{ Object.values(form.errors)[0] }}
        </p>
        <div>
            <label for="plan" class="block font-semibold">Karnet</label
            ><select
                id="plan"
                v-model="form.membership_plan_id"
                class="mt-2 min-h-11 w-full rounded-lg border bg-card p-3"
                :disabled="form.processing"
            >
                <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                    {{ plan.name }} — {{ plan.priceLabel }}
                </option>
            </select>
            <p v-if="selected" class="mt-2 text-sm text-muted-foreground">
                {{
                    selected.kind === 'entries'
                        ? `${selected.includedEntries} wejść · bez ograniczenia czasowego`
                        : `${selected.durationMonths} mies. · aktywacja przy pierwszej wizycie`
                }}
            </p>
            <InputError :message="form.errors.membership_plan_id" />
        </div>
        <fieldset>
            <legend class="mb-4 text-xl font-bold">Kupujący</legend>
            <PersonFields
                v-model="form.purchaser"
                prefix="purchaser"
                :email-max-length="254"
                :errors="form.errors"
                :disabled="form.processing"
            />
        </fieldset>
        <label class="flex min-h-11 items-center gap-3"
            ><input
                v-model="form.for_other_person"
                type="checkbox"
                :disabled="form.processing"
            />Zakup dla innej osoby</label
        >
        <fieldset v-if="form.for_other_person">
            <legend class="mb-4 text-xl font-bold">Posiadacz karnetu</legend>
            <PersonFields
                v-model="form.holder"
                prefix="holder"
                :errors="form.errors"
                :disabled="form.processing"
            />
        </fieldset>
        <fieldset>
            <legend class="mb-3 font-semibold">Sposób opłaty w recepcji</legend>
            <label class="mr-6 inline-flex min-h-11 items-center gap-2"
                ><input
                    v-model="form.payment_method"
                    type="radio"
                    value="cash"
                    name="payment_method"
                />Gotówka</label
            ><label class="inline-flex min-h-11 items-center gap-2"
                ><input
                    v-model="form.payment_method"
                    type="radio"
                    value="card"
                    name="payment_method"
                />Karta</label
            >
        </fieldset>
        <ActionButton type="submit" :loading="form.processing"
            >Zapisz zamówienie</ActionButton
        >
    </form>
    <ConfirmDialog
        v-model:open="open"
        title="Zapisać zamówienie?"
        :description="`${selected?.name ?? ''}, ${selected?.priceLabel ?? ''}. Zapis nie potwierdza otrzymania pieniędzy i nie aktywuje karnetu.`"
        confirm-label="Zapisz zamówienie"
        :processing="form.processing"
        @confirm="save"
    />
</template>
