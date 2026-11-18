<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import NewsPostController from '@/actions/App/Http/Controllers/Admin/Cms/NewsPostController';
import ImageUploadFields from '@/components/cms/ImageUploadFields.vue';
import RichTextEditor from '@/components/cms/RichTextEditor.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import type { AdminNewsPost } from '@/types/news';

const props = defineProps<{ post: AdminNewsPost | null }>();
const form = useForm({
    _method: props.post ? 'patch' : 'post',
    title: props.post?.title ?? '',
    excerpt: props.post?.excerpt ?? '',
    body: props.post?.body ?? '',
    published_at: props.post?.publicationLocal ?? '',
    is_published: props.post?.is_published ?? false,
    revision: props.post?.revision ?? 0,
    current_media_id: props.post?.image?.id ?? null,
    remove_image: false,
    image: null as File | null,
    alt_text: props.post?.image?.alt ?? '',
});
function save() {
    form.post(
        props.post
            ? NewsPostController.update.url(props.post.id)
            : NewsPostController.store.url(),
        { forceFormData: true, preserveScroll: true },
    );
}
</script>
<template>
    <Head :title="post ? 'Edytuj aktualność' : 'Dodaj aktualność'" />
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <Link
            :href="NewsPostController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Aktualności</Link
        >
        <h1 class="font-heading text-3xl sm:text-4xl">
            {{ post ? 'Edytuj aktualność' : 'Dodaj aktualność' }}
        </h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                v-if="post"
                :href="NewsPostController.edit(post.id)"
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-6" @submit.prevent="save">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Treść aktualności</legend>
                <FormField
                    id="title"
                    v-model="form.title"
                    label="Tytuł"
                    maxlength="200"
                    required
                    :error="form.errors.title"
                />
                <FormField
                    id="excerpt"
                    v-model="form.excerpt"
                    label="Krótki wstęp (opcjonalnie)"
                    hint="Wyświetlany na liście i przed treścią wpisu."
                    maxlength="1000"
                    :error="form.errors.excerpt"
                />
                <RichTextEditor
                    v-model="form.body"
                    :disabled="form.processing"
                    :error="form.errors.body"
                />
            </fieldset>
            <fieldset
                :disabled="form.processing"
                class="grid gap-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Publikacja</legend>
                <h2 class="font-heading text-xl">Publikacja</h2>
                <FormField
                    id="published_at"
                    v-model="form.published_at"
                    type="datetime-local"
                    label="Data publikacji (opcjonalnie)"
                    hint="Czas polski. Puste pole oznacza publikację od razu po jej włączeniu. Przyszła data zaplanuje publikację."
                    :error="form.errors.published_at"
                />
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 accent-primary"
                    />Włącz publikację zgodnie z wybraną datą</label
                ><InputError :message="form.errors.is_published" />
                <p class="text-sm text-muted-foreground">
                    Bez włączonej publikacji wpis pozostanie ukryty, także po
                    upływie wybranej daty.
                </p>
            </fieldset>
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Zdjęcie</legend>
                <h2 class="font-heading text-xl">Zdjęcie (opcjonalnie)</h2>
                <ImageUploadFields
                    v-model:file="form.image"
                    v-model:alt="form.alt_text"
                    :existing-image="form.remove_image ? null : post?.image"
                    :disabled="form.processing"
                    :error="form.errors.image || form.errors.current_media_id"
                    :alt-error="form.errors.alt_text"
                    @update:file="
                        form.clearErrors('image');
                        form.remove_image = false;
                    "
                />
                <label
                    v-if="post?.image"
                    for="remove_image"
                    class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        id="remove_image"
                        v-model="form.remove_image"
                        type="checkbox"
                        :disabled="!!form.image"
                        class="size-5 accent-primary"
                    />Usuń zdjęcie przy zapisie</label
                ><InputError :message="form.errors.remove_image" />
                <p v-if="form.progress" role="status" class="text-sm">
                    Przesyłanie: {{ form.progress.percentage ?? 0 }}%
                </p>
            </fieldset>
            <ActionButton
                type="submit"
                :loading="form.processing"
                loading-text="Zapisywanie aktualności…"
                >{{ post ? 'Zapisz zmiany' : 'Dodaj aktualność' }}</ActionButton
            >
        </form>
    </div>
</template>
