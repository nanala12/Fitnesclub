<script setup lang="ts">
import PublicHeading from '@/components/public/PublicHeading.vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import NewsCards from '@/components/public/NewsCards.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { index } from '@/routes/public/news';
import type { NewsCard, PublicPagination } from '@/types/public';
defineProps<{ posts: PublicPagination<NewsCard> }>();
</script>
<template>
    <PublicSeo
        title="Aktualności"
        description="Informacje, wydarzenia i aktualności z życia ActiveFit."
        :canonical="index.url()"
    /><PublicHeading
        eyebrow="Co u nas słychać"
        title="Aktualności"
        description="Informacje o zajęciach, ofercie i wydarzeniach z życia klubu."
    /><NewsCards :posts="posts.data" />
    <div class="mt-8"><Pagination :links="posts.links" /></div>
</template>
