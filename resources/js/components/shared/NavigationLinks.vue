<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';
withDefaults(
    defineProps<{
        items: NavItem[];
        inverse?: boolean;
        horizontal?: boolean;
    }>(),
    { inverse: false, horizontal: false },
);
const emit = defineEmits<{ select: [] }>();
const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <ul :class="horizontal ? 'flex items-center gap-1' : 'grid gap-1'">
        <li v-for="item in items" :key="toUrl(item.href)">
            <Link
                :href="item.href"
                :aria-current="isCurrentUrl(item.href) ? 'page' : undefined"
                :class="[
                    'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
                    inverse
                        ? 'text-sidebar-foreground hover:bg-white/10 focus-visible:outline-white aria-[current=page]:bg-white/15'
                        : 'text-foreground hover:bg-muted focus-visible:outline-ring aria-[current=page]:bg-muted',
                    isCurrentUrl(item.href) ? 'font-semibold' : '',
                ]"
                @click="emit('select')"
            >
                <component
                    :is="item.icon"
                    v-if="item.icon"
                    class="size-5 shrink-0"
                    aria-hidden="true"
                />
                {{ item.title }}
            </Link>
        </li>
    </ul>
</template>
