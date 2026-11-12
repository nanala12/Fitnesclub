<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
withDefaults(defineProps<{ label?: string }>(), { label: 'Wczytywanie…' });
</script>

<template>
    <div
        role="status"
        class="flex items-center justify-center gap-3 p-6 text-sm text-muted-foreground"
    >
        <LoaderCircle class="size-5 animate-spin" aria-hidden="true" />
        <span>{{ label }}</span>
    </div>
</template>
