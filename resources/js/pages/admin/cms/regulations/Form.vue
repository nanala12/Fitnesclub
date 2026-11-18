<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import RegulationController from '@/actions/App/Http/Controllers/Admin/Cms/RegulationController';
import ImageUploadFields from '@/components/cms/ImageUploadFields.vue';
import RichTextEditor from '@/components/cms/RichTextEditor.vue';
import RichTextContent from '@/components/cms/RichTextContent.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import InputError from '@/components/InputError.vue';
import type { RegulationDetail } from '@/types/regulations';
const props = defineProps<{
    version: RegulationDetail | null;
    revision: number;
    initialBody: string;
}>();
const readOnly = props.version?.publishedAt != null;
const form = useForm({
    _method: props.version ? 'patch' : 'post',
    body: props.version?.body ?? props.initialBody,
    revision: props.revision,
    current_media_id: props.version?.image?.id ?? null,
    image: null as File | null,
    alt_text: props.version?.image?.alt ?? '',
    remove_image: false,
});
const confirmOpen = ref(false);
function requestSave() {
    if (!readOnly && !form.processing) confirmOpen.value = true;
}
function save() {
    if (readOnly || form.processing || !confirmOpen.value) return;
    form.post(
        props.version
            ? RegulationController.update.url(props.version.id)
            : RegulationController.store.url(),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
            },
            onError: () => {
                confirmOpen.value = false;
            },
        },
    );
}
</script>
<template>
    <Head
        :title="
            readOnly
                ? 'Podgląd regulaminu'
                : version
                  ? 'Edytuj szkic regulaminu'
                  : 'Nowa wersja regulaminu'
        "
    />
    <div class="mx-auto max-w-3xl space-y-6">
        <Link
            :href="RegulationController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Regulamin i historia</Link
        >
        <h1 class="font-heading text-3xl">
            {{
                readOnly
                    ? `Regulamin — wersja ${version?.versionNumber}`
                    : version
                      ? `Edytuj szkic — wersja ${version.versionNumber}`
                      : 'Nowa wersja regulaminu'
            }}
        </h1>
        <template v-if="readOnly && version">
            <FeedbackMessage title="Opublikowana wersja pozostaje w historii"
                ><Link :href="RegulationController.create()" class="underline"
                    >Utwórz nową wersję na podstawie aktualnego regulaminu</Link
                ></FeedbackMessage
            >
            <ContentImage
                v-if="version.image"
                :src="version.image.src"
                :alt="version.image.alt"
                class="w-full rounded-xl"
            />
            <RichTextContent
                :html="version.body"
                class="rounded-2xl border border-border bg-card p-4 whitespace-pre-line sm:p-6"
            />
        </template>
        <form v-else class="space-y-5" @submit.prevent="requestSave">
            <p class="text-sm text-muted-foreground">
                Zapis tworzy szkic. Publikację potwierdzisz na liście wersji.
                Nowy szkic zawiera treść aktualnego regulaminu; zdjęcie możesz
                dodać osobno.
            </p>
            <FeedbackMessage
                v-if="form.errors.revision"
                variant="error"
                :title="form.errors.revision"
                ><Link
                    :href="
                        version
                            ? RegulationController.edit(version.id)
                            : RegulationController.create()
                    "
                    class="underline"
                    >Wczytaj aktualne dane</Link
                ></FeedbackMessage
            >
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Treść i zdjęcie regulaminu</legend>
                <RichTextEditor
                    v-model="form.body"
                    :disabled="form.processing"
                    :error="form.errors.body"
                />
                <ImageUploadFields
                    v-model:file="form.image"
                    v-model:alt="form.alt_text"
                    :existing-image="form.remove_image ? null : version?.image"
                    :disabled="form.processing"
                    :error="form.errors.image || form.errors.current_media_id"
                    :alt-error="form.errors.alt_text"
                    @update:file="
                        form.clearErrors('image');
                        form.remove_image = false;
                    "
                />
                <label
                    v-if="version?.image"
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
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    loading-text="Zapisywanie szkicu…"
                    >Zapisz szkic</ActionButton
                ><Link
                    :href="RegulationController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
        <ConfirmDialog
            v-if="!readOnly"
            v-model:open="confirmOpen"
            title="Zapisać szkic regulaminu?"
            :description="
                version
                    ? 'Zmiany zostaną zapisane w szkicu. Aktualny regulamin na stronie pozostanie bez zmian do osobnego potwierdzenia publikacji.'
                    : 'Powstanie nowy szkic regulaminu. Opublikujesz go osobno z listy wersji.'
            "
            confirm-label="Potwierdź zapis"
            :processing="form.processing"
            @confirm="save"
        />
    </div>
</template>
