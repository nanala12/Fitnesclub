<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
import { ref, watch } from 'vue';
const props = withDefaults(
    defineProps<{
        src: string | null;
        alt: string;
        width?: number;
        height?: number;
        aspectRatio?: string;
        loading?: 'lazy' | 'eager';
    }>(),
    { aspectRatio: '16 / 9', loading: 'lazy' },
);
const loaded = ref(false);
const failed = ref(false);
watch(
    () => props.src,
    () => {
        loaded.value = false;
        failed.value = false;
    },
);
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl bg-muted"
        :style="{ aspectRatio }"
    >
        <div
            v-if="!src || failed"
            :role="alt ? 'img' : undefined"
            :aria-label="alt || undefined"
            :aria-hidden="!alt || undefined"
            class="flex h-full flex-col items-center justify-center gap-2 p-4 text-center text-sm text-muted-foreground"
        >
            <ImageOff class="size-6" aria-hidden="true" />
            <span>{{
                failed ? 'Nie udało się wczytać zdjęcia' : 'Brak zdjęcia'
            }}</span>
        </div>
        <template v-else>
            <div
                v-if="!loaded"
                aria-hidden="true"
                class="absolute inset-0 animate-pulse bg-muted"
            />
            <img
                :src="src"
                :alt="alt"
                :width="width"
                :height="height"
                :loading="loading"
                decoding="async"
                class="h-full w-full object-cover"
                @load="loaded = true"
                @error="failed = true"
            />
        </template>
    </div>
</template>
