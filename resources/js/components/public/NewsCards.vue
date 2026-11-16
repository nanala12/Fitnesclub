<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import ContentImage from '@/components/shared/ContentImage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import type { NewsCard } from '@/types/public';
import type { SharedPageProps } from '@/types';
defineProps<{ posts: NewsCard[] }>();
const page = usePage<SharedPageProps>();
function date(value: string) {
    return new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'long',
        timeZone: page.props.businessTimezone,
    }).format(new Date(value));
}
</script>
<template>
    <div v-if="posts.length" class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        <article
            v-for="post in posts"
            :key="post.id"
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <ContentImage
                :src="post.image?.src ?? null"
                :alt="post.image?.alt ?? ''"
                aspect-ratio="16/10"
            />
            <div class="space-y-4 p-6">
                <time
                    :datetime="post.publishedAt"
                    class="text-xs text-muted-foreground"
                    >{{ date(post.publishedAt) }}</time
                >
                <h2 class="text-xl font-bold">
                    <Link
                        :href="post.href"
                        class="focus-visible:outline-2 focus-visible:outline-ring"
                        >{{ post.title }}</Link
                    >
                </h2>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    {{ post.excerpt }}
                </p>
                <Link
                    :href="post.href"
                    class="inline-flex min-h-11 items-center text-sm font-bold text-primary"
                    >Czytaj więcej
                    <span class="sr-only">: {{ post.title }}</span
                    ><span aria-hidden="true" class="ml-2">→</span></Link
                >
            </div>
        </article>
    </div>
    <EmptyState
        v-else
        title="Brak aktualności"
        description="Nowe informacje z życia klubu pojawią się tutaj po publikacji."
    />
</template>
