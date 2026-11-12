<script setup lang="ts">
import { CircleCheck, Info, TriangleAlert } from '@lucide/vue';
withDefaults(
    defineProps<{
        title: string;
        variant?: 'info' | 'success' | 'warning' | 'error';
    }>(),
    { variant: 'info' },
);
const styles = {
    info: 'border-border bg-muted text-foreground',
    success:
        'border-green-800/20 bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100',
    warning:
        'border-amber-800/20 bg-amber-50 text-amber-950 dark:bg-amber-950 dark:text-amber-100',
    error: 'border-destructive/30 bg-destructive/10 text-destructive',
};
</script>

<template>
    <div
        :role="variant === 'error' ? 'alert' : 'status'"
        :class="['flex gap-3 rounded-xl border p-4 text-sm', styles[variant]]"
    >
        <component
            :is="
                variant === 'success'
                    ? CircleCheck
                    : variant === 'info'
                      ? Info
                      : TriangleAlert
            "
            class="mt-0.5 size-5 shrink-0"
            aria-hidden="true"
        />
        <div class="min-w-0 space-y-1 break-words">
            <p class="font-semibold">{{ title }}</p>
            <slot />
        </div>
    </div>
</template>
