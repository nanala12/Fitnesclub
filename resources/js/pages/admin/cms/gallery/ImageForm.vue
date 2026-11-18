<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import GalleryController from '@/actions/App/Http/Controllers/Admin/Cms/GalleryController';
import ImageUploadFields from '@/components/cms/ImageUploadFields.vue';
import FormField from '@/components/shared/FormField.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { GalleryCategoryOption, GalleryPhoto } from '@/types/gallery';
const props = defineProps<{
    image: GalleryPhoto | null;
    revision: number;
    categoryOptions: GalleryCategoryOption[];
    selectedCategory: number | null;
}>();
const form = useForm({
    continue_upload: !props.image,
    _method: props.image ? 'patch' : 'post',
    gallery_category_id:
        props.selectedCategory === null ? '' : String(props.selectedCategory),
    is_published: props.image?.is_published ?? false,
    revision: props.revision,
    current_media_id: props.image?.image.id ?? null,
    image: null as File | null,
    alt_text: props.image?.image.alt ?? '',
});
type Upload = {
    file: File;
    alt: string;
    preview: string;
    done: boolean;
    error: string;
};
const uploads = ref<Upload[]>([]);
const uploading = ref(false);
function selectFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        uploads.value.push({
            file,
            alt: '',
            preview: URL.createObjectURL(file),
            done: false,
            error: '',
        });
    }
    input.value = '';
}
function removeUpload(index: number) {
    URL.revokeObjectURL(uploads.value[index].preview);
    uploads.value.splice(index, 1);
}
onBeforeUnmount(() =>
    uploads.value.forEach((upload) => URL.revokeObjectURL(upload.preview)),
);
async function save() {
    if (uploading.value) return;
    if (props.image) {
        form.post(GalleryController.imageUpdate.url(props.image.id), {
            forceFormData: true,
            preserveScroll: true,
        });
        return;
    }
    uploading.value = true;
    for (const upload of uploads.value.filter((item) => !item.done)) {
        upload.error = '';
        form.image = upload.file;
        form.alt_text = upload.alt;
        form.clearErrors();
        const success = await new Promise<boolean>((resolve) => {
            let saved = false;
            form.post(GalleryController.imageStore.url(), {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    form.revision = Number(page.props.revision);
                    upload.done = true;
                    saved = true;
                },
                onError: (errors) => {
                    upload.error = Object.values(errors).join(' ');
                },
                onFinish: () => {
                    if (!saved && !upload.error)
                        upload.error =
                            'Przesyłanie przerwano. Spróbuj ponownie.';
                    resolve(saved);
                },
            });
        });
        if (!success) {
            uploading.value = false;
            return;
        }
    }
    uploading.value = false;
    router.visit(
        GalleryController.index({
            query: { kategoria: form.gallery_category_id },
        }),
        { onSuccess: () => toast.success('Zdjęcia zostały dodane.') },
    );
}
</script>
<template>
    <Head
        :title="image ? 'Edytuj zdjęcie galerii' : 'Dodaj zdjęcia do galerii'"
    />
    <div class="mx-auto max-w-2xl space-y-6">
        <Link
            :href="GalleryController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Galeria</Link
        >
        <h1 class="font-heading text-3xl">
            {{ image ? 'Edytuj zdjęcie galerii' : 'Dodaj zdjęcia do galerii' }}
        </h1>
        <FeedbackMessage
            v-if="!categoryOptions.length"
            title="Najpierw dodaj kategorię"
            ><Link :href="GalleryController.categoryCreate()" class="underline"
                >Dodaj kategorię galerii</Link
            ></FeedbackMessage
        >
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                :href="
                    image
                        ? GalleryController.imageEdit(image.id)
                        : GalleryController.imageCreate()
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-5" @submit.prevent="save">
            <fieldset
                :disabled="form.processing || uploading"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Zdjęcie i kategoria</legend>
                <div class="grid gap-2">
                    <Label for="gallery_category_id">Kategoria</Label
                    ><select
                        id="gallery_category_id"
                        v-model="form.gallery_category_id"
                        required
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-background px-3 text-sm"
                        :aria-invalid="!!form.errors.gallery_category_id"
                        aria-describedby="category-hint category-error"
                    >
                        <option value="" disabled>Wybierz kategorię</option>
                        <option
                            v-for="category in categoryOptions"
                            :key="category.id"
                            :value="String(category.id)"
                        >
                            {{ category.name
                            }}{{ category.is_published ? '' : ' (ukryta)' }}
                        </option>
                    </select>
                    <p id="category-hint" class="text-sm text-muted-foreground">
                        Zdjęcie należy do jednej kategorii. Po przeniesieniu
                        pojawi się na końcu nowej listy.
                    </p>
                    <InputError
                        id="category-error"
                        :message="form.errors.gallery_category_id"
                    />
                </div>
                <div v-if="!image" class="grid min-w-0 gap-4">
                    <Label for="gallery-files">Wybierz zdjęcia</Label>
                    <input
                        id="gallery-files"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/avif"
                        :required="!uploads.length"
                        :disabled="uploading || !categoryOptions.length"
                        aria-describedby="gallery-files-hint"
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input p-2 text-sm"
                        @change="selectFiles"
                    />
                    <p
                        id="gallery-files-hint"
                        class="text-sm text-muted-foreground"
                    >
                        Możesz wybrać wiele zdjęć. JPEG, PNG, WebP lub AVIF,
                        każde do 5 MB, 6000 pikseli na bok i 24 milionów pikseli
                        łącznie. Kolejny wybór dodaje zdjęcia do listy.
                    </p>
                    <p v-if="uploads.length" role="status" class="text-sm">
                        Wybrano: {{ uploads.length }}. Zapisano:
                        {{ uploads.filter((item) => item.done).length }}.
                    </p>
                    <div
                        v-for="(upload, index) in uploads"
                        :key="upload.preview"
                        class="grid min-w-0 gap-3 rounded-xl border border-border p-3"
                    >
                        <img
                            :src="upload.preview"
                            alt=""
                            class="aspect-video w-full rounded-lg object-cover"
                        />
                        <p class="text-sm break-words">
                            {{ upload.file.name }} —
                            {{ upload.done ? 'Zapisano' : 'Do przesłania' }}
                        </p>
                        <FormField
                            :id="`upload-alt-${index}`"
                            v-model="upload.alt"
                            label="Tekst alternatywny zdjęcia"
                            maxlength="255"
                            :disabled="uploading || upload.done"
                        />
                        <InputError :message="upload.error" />
                        <ActionButton
                            v-if="!upload.done"
                            variant="outline"
                            :disabled="uploading"
                            @click="removeUpload(index)"
                            >Usuń z listy</ActionButton
                        >
                    </div>
                </div>
                <ImageUploadFields
                    v-else
                    v-model:file="form.image"
                    v-model:alt="form.alt_text"
                    :existing-image="image?.image"
                    required
                    :disabled="form.processing || !categoryOptions.length"
                    :error="form.errors.image || form.errors.current_media_id"
                    :alt-error="form.errors.alt_text"
                    @update:file="form.clearErrors('image')"
                />
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 accent-primary"
                    />Pokaż zdjęcie na stronie</label
                ><InputError :message="form.errors.is_published" />
                <p class="text-sm text-muted-foreground">
                    Zdjęcie będzie widoczne, gdy publikacja jest włączona
                    również dla jego kategorii. Nowe zdjęcie domyślnie pozostaje
                    ukryte.
                </p>
                <p v-if="form.progress" role="status" class="text-sm">
                    Przesyłanie zdjęcia: {{ form.progress.percentage ?? 0 }}%
                </p>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing || uploading"
                    :disabled="
                        !categoryOptions.length ||
                        (!image && !uploads.some((item) => !item.done))
                    "
                    loading-text="Zapisywanie zdjęcia…"
                    >{{
                        image ? 'Zapisz zmiany' : 'Dodaj zdjęcia'
                    }}</ActionButton
                ><Link
                    v-if="!uploading"
                    :href="GalleryController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
