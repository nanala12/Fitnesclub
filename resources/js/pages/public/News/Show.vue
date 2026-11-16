<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PublicSeo from '@/components/public/PublicSeo.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import { index } from '@/routes/public/news';
import type { NewsCard } from '@/types/public';
import type { SharedPageProps } from '@/types';
defineProps<{ post: NewsCard & { slug: string; bodyText: string } }>();
const page = usePage<SharedPageProps>();
function date(value: string) {
    return new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'long',
        timeZone: page.props.businessTimezone,
    }).format(new Date(value));
}
</script>
<template>
    <PublicSeo
        :title="post.title"
        :description="post.excerpt"
        :canonical="post.href"
    />
    <article class="mx-auto max-w-3xl space-y-7">
        <Link
            :href="index()"
            class="inline-flex min-h-11 items-center text-sm font-bold text-primary"
            >← Wszystkie aktualności</Link
        ><time
            :datetime="post.publishedAt"
            class="block text-sm text-muted-foreground"
            >{{ date(post.publishedAt) }}</time
        >
        <h1
            class="text-4xl leading-tight font-extrabold tracking-tight sm:text-5xl"
        >
            {{ post.title }}
        </h1>
        <p
            v-if="post.excerpt"
            class="text-lg leading-relaxed text-muted-foreground"
        >
            {{ post.excerpt }}
        </p>
        <ContentImage
            v-if="post.image"
            :src="post.image.src"
            :alt="post.image.alt"
            aspect-ratio="16/10"
            loading="eager"
        />
        <div class="text-base leading-relaxed whitespace-pre-line">
            {{ post.bodyText }}
        </div>
    </article>
</template>
