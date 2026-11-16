<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import PublicHeading from '@/components/public/PublicHeading.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { gallery } from '@/routes/public';
import type { PublicImage, PublicPagination } from '@/types/public';
const props = defineProps<{
    categories: { id: number; name: string; description: string }[];
    images: PublicPagination<{
        id: number;
        categoryId: number;
        image: PublicImage | null;
    }>;
    selectedCategory: number | null;
}>();
const open = ref(false);
const selected = ref(0);
const active = computed(() => props.images.data[selected.value]);
function move(offset: number) {
    selected.value =
        (selected.value + offset + props.images.data.length) %
        props.images.data.length;
}
</script>
<template>
    <PublicSeo
        title="Galeria"
        description="Zobacz opublikowane zdjęcia klubu ActiveFit."
        :canonical="gallery.url()"
    /><PublicHeading
        eyebrow="Zobacz ActiveFit od środka"
        title="Galeria"
        description="Poznaj przestrzeń klubu i zdjęcia opublikowane przez nasz zespół."
    />
    <nav aria-label="Kategorie galerii" class="mb-8 flex flex-wrap gap-2">
        <Link
            :href="gallery()"
            :aria-current="selectedCategory === null ? 'page' : undefined"
            class="inline-flex min-h-11 items-center rounded-full border border-border px-5 text-sm font-semibold aria-[current=page]:bg-primary aria-[current=page]:text-primary-foreground"
            >Wszystkie zdjęcia</Link
        ><Link
            v-for="category in categories"
            :key="category.id"
            :href="gallery({ query: { kategoria: category.id } })"
            :aria-current="
                selectedCategory === category.id ? 'page' : undefined
            "
            class="inline-flex min-h-11 items-center rounded-full border border-border px-5 text-sm font-semibold aria-[current=page]:bg-primary aria-[current=page]:text-primary-foreground"
            >{{ category.name }}</Link
        >
    </nav>
    <p v-if="selectedCategory" class="mb-6 text-muted-foreground">
        {{
            categories.find((category) => category.id === selectedCategory)
                ?.description
        }}
    </p>
    <div
        v-if="images.data.length"
        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
    >
        <button
            v-for="(item, index) in images.data"
            :key="item.id"
            type="button"
            :disabled="!item.image"
            :aria-label="`Otwórz zdjęcie: ${item.image?.alt || 'Zdjęcie galerii'}`"
            class="overflow-hidden rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
            @click="
                selected = index;
                open = true;
            "
        >
            <ContentImage
                :src="item.image?.src ?? null"
                :alt="item.image?.alt ?? ''"
                aspect-ratio="4/3"
            />
        </button>
    </div>
    <EmptyState
        v-else
        title="Brak zdjęć"
        description="Zdjęcia pojawią się tutaj po opublikowaniu przez klub."
    />
    <div class="mt-8"><Pagination :links="images.links" /></div>
    <Dialog v-model:open="open"
        ><DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-4xl"
            @keydown.left.prevent="move(-1)"
            @keydown.right.prevent="move(1)"
            ><DialogHeader
                ><DialogTitle>Zdjęcie z galerii</DialogTitle
                ><DialogDescription>{{
                    categories.find(
                        (category) => category.id === active?.categoryId,
                    )?.name
                }}</DialogDescription></DialogHeader
            ><ContentImage
                v-if="active?.image"
                :src="active.image.src"
                :alt="active.image.alt"
                loading="eager"
                aspect-ratio="4/3"
            />
            <div
                v-if="images.data.length > 1"
                class="flex flex-wrap justify-between gap-3"
            >
                <ActionButton variant="outline" @click="move(-1)"
                    >Poprzednie zdjęcie</ActionButton
                ><ActionButton variant="outline" @click="move(1)"
                    >Następne zdjęcie</ActionButton
                >
            </div></DialogContent
        ></Dialog
    >
</template>
