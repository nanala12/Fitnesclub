<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import GalleryController from '@/actions/App/Http/Controllers/Admin/Cms/GalleryController';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { GalleryCategory } from '@/types/gallery';
const props = defineProps<{
    category: GalleryCategory | null;
    revision: number;
}>();
const form = useForm({
    name: props.category?.name ?? '',
    description: props.category?.description ?? '',
    is_published: props.category?.is_published ?? false,
    revision: props.revision,
});
function save() {
    if (props.category)
        form.patch(GalleryController.categoryUpdate.url(props.category.id));
    else form.post(GalleryController.categoryStore.url());
}
</script>
<template>
    <Head
        :title="
            category ? 'Edytuj kategorię galerii' : 'Dodaj kategorię galerii'
        "
    />
    <div class="mx-auto max-w-2xl space-y-6">
        <Link
            :href="GalleryController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Galeria</Link
        >
        <h1 class="font-heading text-3xl">
            {{
                category
                    ? 'Edytuj kategorię galerii'
                    : 'Dodaj kategorię galerii'
            }}
        </h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                :href="
                    category
                        ? GalleryController.categoryEdit(category.id)
                        : GalleryController.categoryCreate()
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-5" @submit.prevent="save">
            <fieldset
                :disabled="form.processing"
                class="grid gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Dane kategorii</legend>
                <FormField
                    id="name"
                    v-model="form.name"
                    label="Nazwa kategorii"
                    maxlength="150"
                    required
                    :error="form.errors.name"
                />
                <div class="grid gap-2">
                    <Label for="description">Krótki opis kategorii</Label
                    ><textarea
                        id="description"
                        v-model="form.description"
                        maxlength="500"
                        rows="4"
                        class="w-full min-w-0 rounded-lg border border-input bg-background p-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                        :aria-invalid="!!form.errors.description"
                        aria-describedby="description-error"
                    /><InputError
                        id="description-error"
                        :message="form.errors.description"
                    />
                </div>
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 accent-primary"
                    />Pokaż kategorię na stronie</label
                ><InputError :message="form.errors.is_published" />
                <p class="text-sm text-muted-foreground">
                    Ukrycie kategorii ukrywa również jej zdjęcia. Zdjęcia
                    zachowują własne ustawienie widoczności.
                </p>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    loading-text="Zapisywanie kategorii…"
                    >{{
                        category ? 'Zapisz zmiany' : 'Dodaj kategorię'
                    }}</ActionButton
                ><Link
                    :href="GalleryController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
