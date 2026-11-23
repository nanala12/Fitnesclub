<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MembershipPlanController from '@/actions/App/Http/Controllers/Admin/MembershipPlanController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import type { AdminMembershipPlan } from '@/types/plans';

const props = defineProps<{
    plan: AdminMembershipPlan | null;
    categories: { value: string; label: string }[];
}>();
const form = useForm({
    name: props.plan?.name ?? '',
    category: props.plan?.category ?? 'gym',
    description: props.plan?.description ?? '',
    kind: props.plan?.kind ?? 'time',
    price: props.plan?.price ?? '',
    duration_months: props.plan?.duration_months?.toString() ?? '',
    included_entries: props.plan?.included_entries?.toString() ?? '',
    is_active: props.plan?.is_active ?? false,
    is_public: props.plan?.is_public ?? false,
    is_featured: props.plan?.is_featured ?? false,
    sort_order: props.plan?.sort_order.toString() ?? '0',
    revision: props.plan?.revision ?? 0,
});
const confirmOpen = ref(false);
function save() {
    form.transform((data) => ({
        ...data,
        included_entries:
            data.kind === 'entries' ? data.included_entries || null : null,
        duration_months:
            data.kind === 'time' ? data.duration_months || null : null,
    }));
    const options = {
        preserveScroll: true,
        onFinish: () => {
            confirmOpen.value = false;
        },
    };
    if (props.plan)
        form.patch(MembershipPlanController.update.url(props.plan.id), options);
    else form.post(MembershipPlanController.store.url(), options);
}
</script>
<template>
    <Head :title="plan ? 'Edytuj plan karnetu' : 'Dodaj plan karnetu'" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Link
            :href="MembershipPlanController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Karnety i cennik</Link
        >
        <h1 class="font-heading text-3xl sm:text-4xl">
            {{ plan ? 'Edytuj plan karnetu' : 'Dodaj plan karnetu' }}
        </h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
        >
            <Link
                v-if="plan"
                :href="MembershipPlanController.edit(plan.id)"
                class="underline"
                >Wczytaj aktualne dane</Link
            >
        </FeedbackMessage>
        <p v-if="plan?.hasHistory" class="text-sm text-muted-foreground">
            Zmiany dotyczą przyszłych zakupów. Dane już kupionych karnetów
            pozostaną zachowane.
        </p>
        <form class="space-y-6" @submit.prevent="confirmOpen = true">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Dane planu</legend>
                <FormField
                    id="name"
                    v-model="form.name"
                    label="Nazwa planu"
                    maxlength="180"
                    required
                    :error="form.errors.name"
                />
                <div class="grid gap-2">
                    <label for="category" class="text-sm font-medium"
                        >Kategoria cennika</label
                    >
                    <select
                        id="category"
                        v-model="form.category"
                        required
                        class="min-h-11 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-invalid="!!form.errors.category"
                        aria-describedby="category-hint category-error"
                    >
                        <option
                            v-for="category in categories"
                            :key="category.value"
                            :value="category.value"
                        >
                            {{ category.label }}
                        </option>
                    </select>
                    <p id="category-hint" class="text-sm text-muted-foreground">
                        Ukrycie kategorii ukryje także ten karnet na stronie.
                    </p>
                    <InputError
                        id="category-error"
                        :message="form.errors.category"
                    />
                </div>
                <div class="grid gap-2">
                    <label for="description" class="text-sm font-medium"
                        >Opis (opcjonalnie)</label
                    >
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="5"
                        maxlength="5000"
                        class="w-full min-w-0 rounded-md border border-input bg-card p-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-invalid="!!form.errors.description"
                        :aria-describedby="
                            form.errors.description
                                ? 'description-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="description-error"
                        :message="form.errors.description"
                    />
                </div>
                <div class="grid gap-2">
                    <label for="kind" class="text-sm font-medium"
                        >Rodzaj karnetu</label
                    >
                    <select
                        id="kind"
                        v-model="form.kind"
                        class="min-h-11 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-invalid="!!form.errors.kind"
                        :aria-describedby="
                            form.errors.kind ? 'kind-error' : undefined
                        "
                    >
                        <option value="time">Czasowy — bez limitu wejść</option>
                        <option value="entries">Z limitem wejść</option>
                    </select>
                    <InputError id="kind-error" :message="form.errors.kind" />
                </div>
                <FormField
                    id="price"
                    v-model="form.price"
                    label="Cena (zł)"
                    inputmode="decimal"
                    placeholder="155,00"
                    maxlength="20"
                    required
                    hint="Np. 155,50. Możesz użyć przecinka lub kropki."
                    :error="form.errors.price"
                />
                <FormField
                    v-if="form.kind === 'time'"
                    id="duration_months"
                    v-model="form.duration_months"
                    type="number"
                    min="1"
                    max="65535"
                    step="1"
                    required
                    label="Ważność w miesiącach"
                    hint="Okres ważności rozpoczyna się przy pierwszej wizycie."
                    :error="form.errors.duration_months"
                />
                <FormField
                    v-if="form.kind === 'entries'"
                    id="included_entries"
                    v-model="form.included_entries"
                    type="number"
                    min="1"
                    max="4294967295"
                    step="1"
                    required
                    label="Liczba wejść"
                    hint="Np. 4 lub 8 wejść."
                    :error="form.errors.included_entries"
                />
                <FormField
                    id="sort_order"
                    v-model="form.sort_order"
                    type="number"
                    min="0"
                    max="4294967295"
                    step="1"
                    required
                    label="Kolejność w cenniku"
                    hint="Mniejsza liczba oznacza wcześniejszą pozycję. Przy tej samej liczbie decyduje kolejność dodania."
                    :error="form.errors.sort_order"
                />
            </fieldset>
            <fieldset
                :disabled="form.processing"
                class="grid gap-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Dostępność i widoczność</legend>
                <h2 class="font-heading text-xl">Dostępność i widoczność</h2>
                <label
                    for="is_active"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_active"
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-5 shrink-0 accent-primary"
                    />Plan dostępny w ofercie</label
                >
                <InputError :message="form.errors.is_active" />
                <label
                    for="is_public"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_public"
                        v-model="form.is_public"
                        type="checkbox"
                        class="size-5 shrink-0 accent-primary"
                    />Pokaż w publicznym cenniku</label
                >
                <InputError :message="form.errors.is_public" />
                <label
                    for="is_featured"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_featured"
                        v-model="form.is_featured"
                        type="checkbox"
                        class="size-5 shrink-0 accent-primary"
                    />Wyróżnij na stronie głównej</label
                >
                <InputError :message="form.errors.is_featured" />
                <p class="text-sm text-muted-foreground">
                    Na stronie pojawi się tylko plan dostępny i widoczny w
                    cenniku. Możesz zaznaczyć wyróżnienie dla najwyżej trzech
                    planów, łącznie z ukrytymi.
                </p>
            </fieldset>
            <ActionButton
                type="submit"
                :loading="form.processing"
                loading-text="Zapisywanie planu…"
                >{{
                    plan ? 'Zapisz zmiany' : 'Dodaj plan karnetu'
                }}</ActionButton
            >
        </form>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Zapisać plan karnetu?"
            description="Zatwierdzone dane i cena będą używane przy nowych zakupach. Widoczność na stronie zależy od wybranych ustawień."
            confirm-label="Zapisz plan"
            :processing="form.processing"
            @confirm="save"
        />
    </div>
</template>
