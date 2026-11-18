<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ContentImage from '@/components/shared/ContentImage.vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import { Button } from '@/components/ui/button';
import { offer as offerIndex } from '@/routes/public';
import type { OfferDetail } from '@/types/public';

defineProps<{ offer: OfferDetail }>();
</script>
<template>
    <PublicSeo
        :title="offer.title"
        :description="offer.description"
        :canonical="offer.href"
    />
    <article class="mx-auto w-full max-w-4xl space-y-7">
        <Link
            :href="offerIndex()"
            class="inline-flex min-h-11 items-center rounded-lg font-semibold text-primary underline underline-offset-4"
            >← Wszystkie oferty</Link
        >
        <h1
            class="text-3xl font-extrabold tracking-tight break-words sm:text-4xl"
        >
            {{ offer.title }}
        </h1>
        <ContentImage
            v-if="offer.image"
            :src="offer.image.src"
            :alt="offer.image.alt"
            class="w-full rounded-2xl"
        />
        <p
            v-if="offer.description"
            class="text-base leading-relaxed break-words whitespace-pre-line text-muted-foreground sm:text-lg"
        >
            {{ offer.description }}
        </p>
        <Button v-if="offer.nextHref" as-child
            ><Link :href="offer.nextHref"
                >{{ offer.nextLabel }} <span aria-hidden="true">→</span></Link
            ></Button
        >
    </article>
</template>
