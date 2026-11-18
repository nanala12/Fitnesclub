<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import FormField from '@/components/shared/FormField.vue';
import { Label } from '@/components/ui/label';

const props = withDefaults(
    defineProps<{
        existingImage?: { src: string; alt: string } | null;
        disabled?: boolean;
        error?: string;
        altError?: string;
        required?: boolean;
    }>(),
    { existingImage: null, disabled: false, required: false },
);
const file = defineModel<File | null>('file', { default: null });
const alt = defineModel<string>('alt', { default: '' });
const id = useId();
const input = ref<HTMLInputElement>();
const preview = ref<string | null>(null);
function releasePreview() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
}
watch(file, (value) => {
    releasePreview();
    if (value) preview.value = URL.createObjectURL(value);
    else if (input.value) input.value.value = '';
});
onBeforeUnmount(releasePreview);
const image = computed(() =>
    preview.value
        ? { src: preview.value, alt: alt.value }
        : props.existingImage,
);
function select(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>
<template>
    <div class="grid min-w-0 gap-4">
        <ContentImage
            v-if="image"
            :src="image.src"
            :alt="image.alt"
            class="aspect-video w-full max-w-lg rounded-xl object-cover"
        />
        <p v-else class="text-sm text-muted-foreground">
            Nie dodano jeszcze zdjęcia.
        </p>
        <div class="grid min-w-0 gap-2">
            <Label :for="`${id}-file`"
                >{{ existingImage ? 'Wybierz nowe zdjęcie' : 'Wybierz zdjęcie'
                }}{{ required ? ' (wymagane)' : '' }}</Label
            >
            <input
                :id="`${id}-file`"
                ref="input"
                type="file"
                name="image"
                accept="image/jpeg,image/png,image/webp,image/avif"
                :required="required && !existingImage"
                :disabled="disabled"
                :aria-describedby="`${id}-hint ${id}-error`"
                :aria-invalid="!!error"
                class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-background p-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                @change="select"
            />
            <p :id="`${id}-hint`" class="text-sm text-muted-foreground">
                JPEG, PNG, WebP lub AVIF. Do 5 MB, 6000 pikseli na bok i 24
                milionów pikseli łącznie.
            </p>
            <InputError :id="`${id}-error`" :message="error" />
        </div>
        <FormField
            :id="`${id}-alt`"
            v-model="alt"
            label="Tekst alternatywny zdjęcia"
            hint="Krótko opisz to, co widać na zdjęciu, dla osób korzystających z czytnika ekranu."
            maxlength="255"
            :error="altError"
            :disabled="disabled || !image"
        />
    </div>
</template>
