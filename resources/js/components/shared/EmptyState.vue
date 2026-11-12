<script setup lang="ts">
import { Inbox } from '@lucide/vue';
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border bg-card px-5 py-10 text-center"
    >
        <Inbox class="size-8 text-muted-foreground" aria-hidden="true" />
        <h2 class="text-lg font-semibold">{{ title }}</h2>
        <p v-if="description" class="max-w-md text-sm text-muted-foreground">
            {{ description }}
        </p>
        <slot />
    </div>
</template>
