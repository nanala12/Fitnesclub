<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import HomepageContentController from '@/actions/App/Http/Controllers/Admin/Cms/HomepageContentController';
import ImageUploadFields from '@/components/cms/ImageUploadFields.vue';
import InputError from '@/components/InputError.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import { Label } from '@/components/ui/label';
import type { HomepageItem, HomepageSection } from '@/types/homepage';

const props = defineProps<{
    section: HomepageSection;
    revision: number;
    item: HomepageItem | null;
}>();
const isTiles = computed(() => props.section === 'kafelki');
const title = computed(() =>
    props.item
        ? isTiles.value
            ? 'Edytuj kafelek'
            : 'Edytuj slajd'
        : isTiles.value
          ? 'Dodaj kafelek'
          : 'Dodaj slajd',
);
const values = () => ({
    _method: props.item ? 'patch' : 'post',
    title: props.item?.title ?? '',
    description: props.item?.description ?? '',
    link_url: props.item?.link_url ?? '',
    is_published: props.item?.is_published ?? false,
    revision: props.revision,
    current_media_id: props.item?.image?.id ?? null,
    remove_image: false,
    image: null as File | null,
    alt_text: props.item?.image?.alt ?? '',
});
const form = useForm(values());
function save() {
    const url = props.item
        ? HomepageContentController.update.url({
              section: props.section,
              item: props.item.id,
          })
        : HomepageContentController.store.url(props.section);
    form.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(values());
            form.reset();
        },
    });
}
function choose() {
    form.clearErrors('image', 'current_media_id');
    if (form.image) form.remove_image = false;
}
</script>
<template>
    <Head :title="title" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Link
            :href="HomepageContentController.index(section)"
            class="inline-flex min-h-11 items-center rounded-lg text-sm underline underline-offset-4"
            >← {{ isTiles ? 'Kafelki oferty' : 'Karuzela zdjęć' }}</Link
        >
        <div>
            <p
                class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                Zarządzanie stroną / Start
            </p>
            <h1 class="font-heading text-3xl sm:text-4xl">{{ title }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    item
                        ? 'Zapisz zmiany, aby zaktualizować element na stronie.'
                        : 'Nowy element pojawi się na końcu listy. Domyślnie pozostanie ukryty.'
                }}
            </p>
        </div>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                :href="
                    item
                        ? HomepageContentController.edit({
                              section,
                              item: item.id,
                          })
                        : HomepageContentController.create(section)
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-6" @submit.prevent="save">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Treść elementu</legend>
                <FormField
                    id="title"
                    v-model="form.title"
                    :label="
                        isTiles
                            ? 'Tytuł kafelka'
                            : 'Nagłówek slajdu (opcjonalnie)'
                    "
                    :required="isTiles"
                    maxlength="150"
                    :error="form.errors.title"
                />
                <div class="grid gap-2">
                    <Label for="description">{{
                        isTiles ? 'Opis oferty' : 'Opis slajdu'
                    }}</Label
                    ><textarea
                        class="min-h-28 w-full min-w-0 rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring aria-invalid:border-destructive"
                        id="description"
                        v-model="form.description"
                        :maxlength="isTiles ? 500 : 1000"
                        rows="4"
                        :aria-invalid="!!form.errors.description"
                        aria-describedby="description-hint description-error"
                    />
                    <p
                        id="description-hint"
                        class="text-sm text-muted-foreground"
                    >
                        Zwykły tekst, do {{ isTiles ? 500 : 1000 }} znaków.
                    </p>
                    <InputError
                        id="description-error"
                        :message="form.errors.description"
                    />
                </div>
                <FormField
                    v-if="isTiles"
                    id="link_url"
                    v-model="form.link_url"
                    label="Odnośnik w szczegółach oferty (opcjonalnie)"
                    hint="Opcjonalny dalszy odnośnik, np. /cennik. Kafelek otwiera własną podstronę z pełnym opisem."
                    maxlength="500"
                    :error="form.errors.link_url"
                />
                <div class="grid gap-2">
                    <label
                        for="is_published"
                        class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                        ><input
                            id="is_published"
                            v-model="form.is_published"
                            type="checkbox"
                            class="size-5 rounded border-input accent-primary"
                            :aria-invalid="!!form.errors.is_published"
                            aria-describedby="visibility-hint visibility-error"
                        />Pokaż na stronie</label
                    >
                    <p
                        id="visibility-hint"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            isTiles
                                ? 'Widoczny kafelek pojawi się na stronie głównej i w zakładce Oferta.'
                                : 'Widoczny slajd pojawi się w karuzeli na stronie głównej.'
                        }}
                    </p>
                    <InputError
                        id="visibility-error"
                        :message="form.errors.is_published"
                    />
                </div>
            </fieldset>
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Zdjęcie elementu</legend>
                <h2 class="font-heading text-xl">Zdjęcie</h2>
                <ImageUploadFields
                    v-model:file="form.image"
                    v-model:alt="form.alt_text"
                    :existing-image="form.remove_image ? null : item?.image"
                    :required="!isTiles"
                    :disabled="form.processing"
                    :error="form.errors.image || form.errors.current_media_id"
                    :alt-error="form.errors.alt_text"
                    @update:file="choose"
                />
                <label
                    v-if="isTiles && item?.image"
                    for="remove_image"
                    class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        id="remove_image"
                        v-model="form.remove_image"
                        type="checkbox"
                        :disabled="!!form.image || form.processing"
                        class="size-5 rounded border-input accent-primary"
                    />Usuń bieżące zdjęcie przy zapisie</label
                ><InputError :message="form.errors.remove_image" />
                <p v-if="!isTiles" class="text-sm text-muted-foreground">
                    Każdy slajd ma własne zdjęcie. Usunięcie całego slajdu jest
                    dostępne na liście karuzeli.
                </p>
                <p v-if="form.progress" role="status" class="text-sm">
                    Przesyłanie zdjęcia: {{ form.progress.percentage ?? 0 }}%
                </p>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    loading-text="Zapisywanie zmian…"
                    >{{
                        item
                            ? 'Zapisz zmiany'
                            : isTiles
                              ? 'Dodaj kafelek'
                              : 'Dodaj slajd'
                    }}</ActionButton
                ><Link
                    :href="HomepageContentController.index(section)"
                    class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm underline underline-offset-4"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
