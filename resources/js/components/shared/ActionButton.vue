<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';

withDefaults(
    defineProps<{
        type?: 'button' | 'submit' | 'reset';
        variant?: ButtonVariants['variant'];
        loading?: boolean;
        disabled?: boolean;
        loadingText?: string;
    }>(),
    {
        type: 'button',
        variant: 'default',
        loading: false,
        disabled: false,
        loadingText: 'Proszę czekać…',
    },
);
</script>

<template>
    <Button
        :type="type"
        :variant="variant"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        class="min-h-11"
    >
        <LoaderCircle
            v-if="loading"
            class="size-4 animate-spin"
            aria-hidden="true"
        />
        <span v-if="loading" role="status">{{ loadingText }}</span>
        <slot v-else />
    </Button>
</template>
