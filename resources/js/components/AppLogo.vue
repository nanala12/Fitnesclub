<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BrandLogo from '@/components/shared/BrandLogo.vue';

const name = usePage().props.name;
</script>

<template>
    <span :aria-label="name"><BrandLogo /></span>
</template>
