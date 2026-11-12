<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import type { PanelLayoutProps } from '@/types';
defineProps<PanelLayoutProps>();
</script>

<template>
    <CustomerLayout :breadcrumbs="breadcrumbs" :navigation="navigation"
        ><slot
    /></CustomerLayout>
</template>
