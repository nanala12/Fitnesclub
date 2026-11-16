<script setup lang="ts">
import PublicSeo from '@/components/public/PublicSeo.vue';
import PublicHeading from '@/components/public/PublicHeading.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import { regulations } from '@/routes/public';
import type { PublicImage } from '@/types/public';
defineProps<{
    regulation: {
        id: number;
        versionNumber: number;
        publishedAt: string;
        bodyText: string;
        image: PublicImage | null;
    } | null;
}>();
</script>
<template>
    <PublicSeo
        title="Regulamin"
        description="Aktualny opublikowany regulamin klubu ActiveFit."
        :canonical="regulations.url()"
    /><PublicHeading
        eyebrow="Ważne informacje"
        title="Regulamin klubu"
        description="Zapoznaj się z opublikowanymi zasadami korzystania z usług klubu."
    />
    <article
        v-if="regulation"
        class="mx-auto max-w-4xl space-y-7 rounded-2xl border border-border bg-card p-6 sm:p-10"
    >
        <p class="text-sm text-muted-foreground">
            Wersja {{ regulation.versionNumber }}
        </p>
        <ContentImage
            v-if="regulation.image"
            :src="regulation.image.src"
            :alt="regulation.image.alt"
        />
        <div class="leading-relaxed whitespace-pre-line">
            {{ regulation.bodyText }}
        </div>
    </article>
    <EmptyState
        v-else
        title="Regulamin nie jest jeszcze dostępny"
        description="Aktualna treść pojawi się tutaj po publikacji przez klub."
    />
</template>
