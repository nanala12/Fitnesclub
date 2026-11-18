<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowUp, ArrowDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import GalleryController from '@/actions/App/Http/Controllers/Admin/Cms/GalleryController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    GalleryCategory,
    GalleryCategoryOption,
    GalleryPhoto,
} from '@/types/gallery';
import type { PaginationLink } from '@/types';
import { gallery } from '@/routes/public';
type Page<T> = {
    data: T[];
    total: number;
    current_page: number;
    per_page: number;
    links: PaginationLink[];
};
const props = defineProps<{
    revision: number;
    categories: Page<GalleryCategory>;
    images: Page<GalleryPhoto>;
    categoryOptions: GalleryCategoryOption[];
    selectedCategory: number | null;
}>();
const form = useForm({
    revision: props.revision,
    page: 1,
    categories_page: 1,
    kategoria: null as number | null,
    is_published: false,
    direction: 'up',
    current_media_id: null as number | null,
    image: null,
    category: null,
});
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
const selected = ref<{
    category: boolean;
    id: number;
    mediaId: number | null;
    label: string;
} | null>(null);
const confirmOpen = ref(false);
function prepare() {
    form.clearErrors();
    form.revision = props.revision;
    form.page = props.images.current_page;
    form.categories_page = props.categories.current_page;
    form.kategoria = props.selectedCategory;
}
function visibility(category: boolean, item: GalleryCategory | GalleryPhoto) {
    prepare();
    form.is_published = !item.is_published;
    form.patch(
        category
            ? GalleryController.categoryVisibility.url(item.id)
            : GalleryController.imageVisibility.url(item.id),
        { preserveScroll: true },
    );
}
function move(category: boolean, id: number, direction: 'up' | 'down') {
    prepare();
    form.direction = direction;
    form.patch(
        category
            ? GalleryController.categoryMove.url(id)
            : GalleryController.imageMove.url(id),
        { preserveScroll: true },
    );
}
function confirm(category: boolean, item: GalleryCategory | GalleryPhoto) {
    form.clearErrors();
    selected.value = {
        category,
        id: item.id,
        mediaId: 'image' in item ? item.image.id : null,
        label: 'name' in item ? item.name : `Zdjęcie #${item.id}`,
    };
    confirmOpen.value = true;
}
function remove() {
    if (!selected.value) return;
    prepare();
    form.current_media_id = selected.value.mediaId;
    form.delete(
        selected.value.category
            ? GalleryController.categoryDestroy.url(selected.value.id)
            : GalleryController.imageDestroy.url(selected.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
            },
        },
    );
}
function filter(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    router.get(
        GalleryController.index.url(),
        value ? { kategoria: value } : {},
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head title="Galeria" />
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl sm:text-4xl">Galeria</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Zarządzaj kategoriami, zdjęciami i kolejnością ich
                    wyświetlania.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="gallery()">Podgląd galerii</Link></Button
                ><Button as-child variant="outline"
                    ><Link :href="GalleryController.categoryCreate()"
                        >Dodaj kategorię</Link
                    ></Button
                ><Button as-child
                    ><Link
                        :href="
                            GalleryController.imageCreate({
                                query: {
                                    kategoria: selectedCategory ?? undefined,
                                },
                            })
                        "
                        >Dodaj zdjęcia</Link
                    ></Button
                >
            </div>
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="
                    GalleryController.index({
                        query: { kategoria: selectedCategory ?? undefined },
                    })
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <section aria-labelledby="categories-heading" class="space-y-4">
            <h2 id="categories-heading" class="font-heading text-2xl">
                Kategorie
            </h2>
            <EmptyState
                v-if="!categories.total"
                title="Nie ma jeszcze kategorii"
                description="Dodaj kategorię z nazwą i krótkim opisem, a potem przypisz do niej zdjęcia."
            />
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <article
                    v-for="(category, index) in categories.data"
                    :key="category.id"
                    class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-4"
                    data-gallery-category
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3
                            class="font-heading max-w-full min-w-0 text-xl break-words"
                        >
                            {{ category.name }}
                        </h3>
                        <span class="rounded-full bg-muted px-3 py-1 text-xs"
                            >{{
                                category.is_published ? 'Widoczna' : 'Ukryta'
                            }}
                            · Zdjęć: {{ category.imagesCount }}</span
                        >
                    </div>
                    <p
                        v-if="category.description"
                        class="line-clamp-3 text-sm break-words text-muted-foreground"
                    >
                        {{ category.description }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <ActionButton
                            variant="outline"
                            :disabled="
                                form.processing ||
                                (categories.current_page - 1) *
                                    categories.per_page +
                                    index ===
                                    0
                            "
                            :aria-label="`Przesuń kategorię wyżej: ${category.name}`"
                            @click="move(true, category.id, 'up')"
                            ><ArrowUp class="size-4" />Wyżej</ActionButton
                        ><ActionButton
                            variant="outline"
                            :disabled="
                                form.processing ||
                                (categories.current_page - 1) *
                                    categories.per_page +
                                    index ===
                                    categories.total - 1
                            "
                            :aria-label="`Przesuń kategorię niżej: ${category.name}`"
                            @click="move(true, category.id, 'down')"
                            ><ArrowDown class="size-4" />Niżej</ActionButton
                        ><Button as-child variant="outline"
                            ><Link
                                :href="
                                    GalleryController.categoryEdit(category.id)
                                "
                                >Edytuj<span class="sr-only">
                                    kategorię {{ category.name }}</span
                                ></Link
                            ></Button
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="visibility(true, category)"
                            >{{ category.is_published ? 'Ukryj' : 'Pokaż'
                            }}<span class="sr-only">
                                kategorię {{ category.name }}</span
                            ></ActionButton
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="confirm(true, category)"
                            >Usuń<span class="sr-only">
                                kategorię {{ category.name }}</span
                            ></ActionButton
                        >
                    </div>
                </article>
            </div>
            <Pagination
                v-if="categories.total"
                :links="categories.links"
                aria-label="Strony kategorii galerii"
            />
        </section>
        <section
            aria-labelledby="images-heading"
            class="space-y-4"
            :aria-busy="form.processing"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h2 id="images-heading" class="font-heading text-2xl">
                    Zdjęcia
                </h2>
                <div class="grid w-full min-w-0 gap-2 sm:w-64">
                    <Label for="category-filter">Kategoria zdjęć</Label
                    ><select
                        id="category-filter"
                        :value="selectedCategory ?? ''"
                        :disabled="form.processing"
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm"
                        @change="filter"
                    >
                        <option value="">Wszystkie kategorie</option>
                        <option
                            v-for="category in categoryOptions"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">
                Kolejność zdjęć ustalasz osobno w każdej kategorii, również dla
                ukrytych zdjęć.
            </p>
            <EmptyState
                v-if="!images.total"
                title="Nie ma jeszcze zdjęć"
                description="Wybierz kategorię i dodaj zdjęcie. Nowe zdjęcia domyślnie pozostają ukryte."
            />
            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="photo in images.data"
                    :key="photo.id"
                    class="min-w-0 overflow-hidden rounded-2xl border border-border bg-card"
                    data-gallery-photo
                    :data-photo-id="photo.id"
                >
                    <ContentImage
                        :src="photo.image.src"
                        :alt="photo.image.alt"
                        aspect-ratio="4/3"
                    />
                    <div class="space-y-3 p-4">
                        <h3 class="font-semibold break-words">
                            {{ photo.categoryName }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{
                                !photo.categoryPublished
                                    ? 'Kategoria ukryta'
                                    : photo.is_published
                                      ? 'Widoczne na stronie'
                                      : 'Ukryte'
                            }}
                            · Pozycja {{ photo.position }}
                        </p>
                        <p
                            v-if="!photo.categoryPublished"
                            class="text-xs text-muted-foreground"
                        >
                            Aby pokazać zdjęcie, włącz również widoczność
                            kategorii.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <ActionButton
                                variant="outline"
                                :disabled="form.processing || !photo.canMoveUp"
                                :aria-label="`Przesuń zdjęcie ${photo.id} wyżej`"
                                @click="move(false, photo.id, 'up')"
                                ><ArrowUp class="size-4" />Wyżej</ActionButton
                            ><ActionButton
                                variant="outline"
                                :disabled="
                                    form.processing || !photo.canMoveDown
                                "
                                :aria-label="`Przesuń zdjęcie ${photo.id} niżej`"
                                @click="move(false, photo.id, 'down')"
                                ><ArrowDown class="size-4" />Niżej</ActionButton
                            ><Button as-child variant="outline"
                                ><Link
                                    :href="
                                        GalleryController.imageEdit(photo.id)
                                    "
                                    >Edytuj<span class="sr-only">
                                        zdjęcie {{ photo.id }}</span
                                    ></Link
                                ></Button
                            ><ActionButton
                                variant="outline"
                                :disabled="form.processing"
                                @click="visibility(false, photo)"
                                >{{ photo.is_published ? 'Ukryj' : 'Pokaż'
                                }}<span class="sr-only">
                                    zdjęcie {{ photo.id }}</span
                                ></ActionButton
                            ><ActionButton
                                variant="outline"
                                :disabled="form.processing"
                                @click="confirm(false, photo)"
                                >Usuń<span class="sr-only">
                                    zdjęcie {{ photo.id }}</span
                                ></ActionButton
                            >
                        </div>
                    </div>
                </article>
            </div>
            <Pagination
                :links="images.links"
                aria-label="Strony zdjęć galerii"
            />
        </section>
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                selected?.category ? 'Usunąć kategorię?' : 'Usunąć zdjęcie?'
            "
            :description="
                selected?.category
                    ? `Kategoria „${selected.label}” zostanie usunięta. Najpierw przenieś lub usuń jej zdjęcia.`
                    : 'Zdjęcie zostanie usunięte z galerii i z plików klubu. Tej operacji nie można cofnąć.'
            "
            confirm-label="Usuń"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
