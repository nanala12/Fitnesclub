<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '@/types';
const props = withDefaults(
    defineProps<{ links: PaginationLink[]; loading?: boolean }>(),
    { loading: false },
);
function label(index: number, text: string) {
    if (index === 0) return 'Poprzednia';
    if (index === props.links.length - 1) return 'Następna';
    return text === '&hellip;' ? '…' : text;
}
</script>

<template>
    <nav
        v-if="links.some((link) => link.url && !link.active)"
        aria-label="Strony wyników"
        :aria-busy="loading || undefined"
        class="flex flex-wrap items-center gap-2"
    >
        <template
            v-for="(link, index) in links"
            :key="`${index}-${link.label}`"
        >
            <Link
                v-if="link.url && !loading"
                :href="link.url"
                :aria-current="link.active ? 'page' : undefined"
                :aria-label="
                    index > 0 && index < links.length - 1
                        ? `Strona ${link.label}`
                        : undefined
                "
                :class="[
                    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                    link.active
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-card hover:bg-muted',
                ]"
                preserve-scroll
                >{{ label(index, link.label) }}</Link
            >
            <span
                v-else
                aria-disabled="true"
                :aria-current="link.active ? 'page' : undefined"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-border px-3 text-sm text-muted-foreground"
                >{{ label(index, link.label) }}</span
            >
        </template>
    </nav>
</template>
