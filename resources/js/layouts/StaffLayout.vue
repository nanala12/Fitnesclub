<script setup lang="ts">
import PanelLayout from '@/layouts/PanelLayout.vue';
import type { PanelLayoutProps } from '@/types';
defineProps<PanelLayoutProps>();
</script>

<template>
    <PanelLayout
        title="Panel pracownika"
        :breadcrumbs="breadcrumbs"
        :navigation="navigation"
        ><slot
    /></PanelLayout>
</template>
