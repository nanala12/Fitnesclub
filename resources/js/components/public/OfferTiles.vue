<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ContentImage from '@/components/shared/ContentImage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import type { OfferTile } from '@/types/public';
defineProps<{ tiles: OfferTile[] }>();
</script>
<template>
    <div v-if="tiles.length" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <Link
            v-for="tile in tiles"
            :key="tile.id"
            :href="tile.href"
            class="group overflow-hidden rounded-2xl border border-border bg-card transition-shadow hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
            ><ContentImage
                :src="tile.image?.src ?? null"
                :alt="tile.image?.alt ?? ''"
                aspect-ratio="4/3"
            />
            <div class="space-y-3 p-5">
                <h3 class="text-lg font-bold">{{ tile.title }}</h3>
                <p
                    v-if="tile.description"
                    class="text-sm leading-relaxed text-muted-foreground"
                >
                    {{ tile.description }}
                </p>
                <span
                    class="inline-flex min-h-11 items-center text-sm font-semibold text-primary"
                    >Sprawdź ofertę
                    <span aria-hidden="true" class="ml-2">↗</span></span
                >
            </div></Link
        >
    </div>
    <EmptyState
        v-else
        title="Oferta będzie dostępna wkrótce"
        description="Przygotowujemy informacje o dostępnych formach aktywności."
    />
</template>
