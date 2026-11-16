<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedPageProps } from '@/types';
const props = defineProps<{
    title: string;
    description: string;
    canonical: string;
}>();
const page = usePage<
    SharedPageProps & {
        siteUrl: string;
        seo: { title: string; description: string; canonical: string };
    }
>();
const meta = computed(() => page.props.seo ?? props);
const absoluteUrl = computed(
    () => new URL(meta.value.canonical, page.props.siteUrl).href,
);
</script>
<template>
    <Head :title="meta.title"
        ><meta
            name="description"
            :content="meta.description"
            head-key="description" /><link
            rel="canonical"
            :href="absoluteUrl"
            head-key="canonical" /><meta
            property="og:title"
            :content="`${meta.title} - ${page.props.name}`"
            head-key="og:title" /><meta
            property="og:description"
            :content="meta.description"
            head-key="og:description" /><meta
            property="og:url"
            :content="absoluteUrl"
            head-key="og:url" /><meta
            property="og:type"
            content="website"
            head-key="og:type"
    /></Head>
</template>
