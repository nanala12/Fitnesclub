<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import ImageUploadFields from '@/components/cms/ImageUploadFields.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';

const props = withDefaults(
    defineProps<{
        image: { id: number; src: string; alt: string } | null;
        uploadUrl: string;
        deleteUrl: string;
        disabled?: boolean;
        removeDeletesOwner?: boolean;
    }>(),
    { disabled: false, removeDeletesOwner: false },
);
const id = useId();
const removeOpen = ref(false);
const upload = useForm<{
    image: File | null;
    alt_text: string;
    current_media_id: number | null;
}>({
    image: null,
    alt_text: props.image?.alt ?? '',
    current_media_id: props.image?.id ?? null,
});
const removal = useForm({
    image: null,
    current_media_id: props.image?.id ?? null,
});

function save() {
    upload.current_media_id = props.image?.id ?? null;
    upload.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            upload.image = null;
        },
    });
}
function remove() {
    removal.current_media_id = props.image?.id ?? null;
    removal.delete(props.deleteUrl, {
        preserveScroll: true,
        onSuccess: () => {
            removeOpen.value = false;
        },
    });
}
</script>

<template>
    <section
        class="grid gap-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
        :aria-labelledby="`${id}-title`"
    >
        <h3 :id="`${id}-title`" class="font-heading text-xl">Zdjęcie</h3>
        <form class="grid gap-4" @submit.prevent="save">
            <ImageUploadFields
                v-model:file="upload.image"
                v-model:alt="upload.alt_text"
                :existing-image="image"
                :disabled="disabled || upload.processing || removal.processing"
                :error="upload.errors.image || upload.errors.current_media_id"
                :alt-error="upload.errors.alt_text"
                @update:file="upload.clearErrors()"
            />
            <p v-if="upload.progress" role="status" class="text-sm">
                Przesyłanie zdjęcia: {{ upload.progress.percentage ?? 0 }}%
            </p>
            <ActionButton
                type="submit"
                class="justify-self-start"
                :loading="upload.processing"
                :disabled="disabled || !upload.image || removal.processing"
                loading-text="Zapisywanie zdjęcia…"
                >{{ image ? 'Zastąp zdjęcie' : 'Dodaj zdjęcie' }}</ActionButton
            >
        </form>
        <ConfirmDialog
            v-if="image"
            v-model:open="removeOpen"
            title="Usunąć zdjęcie?"
            :description="
                removeDeletesOwner
                    ? 'Zdjęcie wraz z elementem, do którego należy, zostanie usunięte. Tej operacji nie można cofnąć.'
                    : 'Zdjęcie zostanie usunięte z tej treści i z plików klubu. Tej operacji nie można cofnąć.'
            "
            confirm-label="Usuń zdjęcie"
            destructive
            :processing="removal.processing"
            :error="removal.errors.image || removal.errors.current_media_id"
            @confirm="remove"
        >
            <template #trigger
                ><ActionButton
                    variant="outline"
                    class="justify-self-start"
                    :disabled="disabled || upload.processing"
                    >Usuń zdjęcie</ActionButton
                ></template
            >
        </ConfirmDialog>
    </section>
</template>
