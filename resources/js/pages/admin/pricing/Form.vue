<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PriceListController from '@/actions/App/Http/Controllers/Admin/PriceListController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import type { AdminPriceItem, PriceCategory } from '@/types/pricing';

const props = defineProps<{
    type: 'kategorie' | 'uslugi';
    item: AdminPriceItem | null;
    categories: PriceCategory[];
}>();
const categoryMode = props.type === 'kategorie';
const serviceMode = props.type === 'uslugi';
const title = computed(
    () =>
        `${props.item ? 'Edytuj' : 'Dodaj'} ${categoryMode ? 'kategorię cennika' : 'usługę do cennika'}`,
);
const form = useForm({
    name: props.item?.name ?? '',
    category: props.item?.category ?? 'gym',
    price: props.item?.price ?? '',
    sort_order: props.item?.sort_order.toString() ?? '0',
    is_published: props.item?.is_published ?? false,
    revision: props.item?.revision ?? 0,
});
const confirmOpen = ref(false);
function save() {
    const options = {
        preserveScroll: true,
        onFinish: () => {
            confirmOpen.value = false;
        },
    };
    if (props.item)
        form.patch(
            PriceListController.update.url({
                type: props.type,
                item: props.item.id,
            }),
            options,
        );
    else form.post(PriceListController.store.url(props.type), options);
}
</script>
<template>
    <Head :title="title" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Link
            :href="PriceListController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Kategorie i usługi cennika</Link
        >
        <h1 class="font-heading text-3xl sm:text-4xl">{{ title }}</h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                v-if="item"
                :href="PriceListController.edit({ type, item: item.id })"
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <p v-if="serviceMode" class="text-sm text-muted-foreground">
            Usługa jest pozycją informacyjną w cenniku. Karnety dodawaj w sekcji
            „Karnety i cennik”.
        </p>
        <form class="space-y-6" @submit.prevent="confirmOpen = true">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Dane pozycji cennika</legend>
                <FormField
                    id="name"
                    v-model="form.name"
                    label="Nazwa"
                    maxlength="180"
                    required
                    :error="form.errors.name"
                />
                <template v-if="serviceMode">
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
                            aria-describedby="category-error"
                        >
                            <option
                                v-for="category in categories"
                                :key="category.value"
                                :value="category.value"
                            >
                                {{ category.label }}
                            </option>
                        </select>
                        <InputError
                            id="category-error"
                            :message="form.errors.category"
                        />
                    </div>
                    <FormField
                        id="price"
                        v-model="form.price"
                        label="Cena (zł)"
                        inputmode="decimal"
                        placeholder="120,00"
                        maxlength="20"
                        required
                        :error="form.errors.price"
                    />
                </template>
                <FormField
                    id="sort_order"
                    v-model="form.sort_order"
                    type="number"
                    min="0"
                    max="4294967295"
                    step="1"
                    label="Kolejność"
                    hint="Mniejsza liczba oznacza wcześniejszą pozycję."
                    required
                    :error="form.errors.sort_order"
                />
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 shrink-0 accent-primary"
                    />Pokaż w cenniku</label
                >
                <InputError :message="form.errors.is_published" />
                <p v-if="categoryMode" class="text-sm text-muted-foreground">
                    Nazwa pojawi się na przycisku filtra. Ukrycie kategorii
                    ukrywa także jej usługi i karnety.
                </p>
            </fieldset>
            <ActionButton type="submit" :loading="form.processing"
                >Zapisz zmiany</ActionButton
            >
        </form>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Zapisać zmiany cennika?"
            description="Widoczne pozycje pojawią się na stronie w wybranej kategorii."
            confirm-label="Zapisz cennik"
            :processing="form.processing"
            @confirm="save"
        />
    </div>
</template>
