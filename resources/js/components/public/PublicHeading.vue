<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; description: string }>();
</script>
<template>
    <section class="mb-10 max-w-3xl space-y-4 sm:mb-14">
        <p
            v-if="eyebrow"
            class="text-xs font-bold tracking-[0.15em] text-primary uppercase"
        >
            {{ eyebrow }}
        </p>
        <h1
            class="text-4xl leading-tight font-extrabold tracking-tight sm:text-5xl"
        >
            {{ title }}
        </h1>
        <p
            class="max-w-2xl text-base leading-relaxed text-muted-foreground sm:text-lg"
        >
            {{ description }}
        </p>
    </section>
</template>
