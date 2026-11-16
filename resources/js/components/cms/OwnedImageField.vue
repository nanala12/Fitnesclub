<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import FormField from '@/components/shared/FormField.vue';
import { Label } from '@/components/ui/label';

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
const input = ref<HTMLInputElement>();
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

function selectImage(event: Event) {
    upload.image = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.clearErrors();
}
function save() {
    upload.current_media_id = props.image?.id ?? null;
    upload.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            upload.image = null;
            if (input.value) input.value.value = '';
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
        <ContentImage
            v-if="image"
            :src="image.src"
            :alt="image.alt"
            class="aspect-video w-full max-w-lg rounded-xl object-cover"
        />
        <p v-else class="text-sm text-muted-foreground">
            Nie dodano jeszcze zdjęcia.
        </p>
        <form class="grid gap-4" @submit.prevent="save">
            <div class="grid min-w-0 gap-2">
                <Label :for="`${id}-file`">{{
                    image ? 'Wybierz nowe zdjęcie' : 'Wybierz zdjęcie'
                }}</Label>
                <input
                    :id="`${id}-file`"
                    ref="input"
                    type="file"
                    name="image"
                    accept="image/jpeg,image/png,image/webp,image/avif"
                    :disabled="
                        disabled || upload.processing || removal.processing
                    "
                    :aria-describedby="`${id}-hint ${id}-error`"
                    :aria-invalid="!!upload.errors.image"
                    class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-background p-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    @change="selectImage"
                />
                <p :id="`${id}-hint`" class="text-sm text-muted-foreground">
                    JPEG, PNG, WebP lub AVIF. Do 5 MB, 6000 pikseli na bok i 24
                    milionów pikseli łącznie.
                </p>
                <InputError
                    :id="`${id}-error`"
                    :message="
                        upload.errors.image || upload.errors.current_media_id
                    "
                />
            </div>
            <FormField
                :id="`${id}-alt`"
                v-model="upload.alt_text"
                label="Tekst alternatywny"
                hint="Krótko opisz to, co widać na zdjęciu, dla osób korzystających z czytnika ekranu."
                maxlength="255"
                :error="upload.errors.alt_text"
                :disabled="disabled || upload.processing || removal.processing"
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
