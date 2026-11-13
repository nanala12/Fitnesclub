<script setup lang="ts">
import { LayoutGrid, UserRound } from '@lucide/vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { dashboard } from '@/routes/staff';
import { edit } from '@/routes/profile';
import type { PanelLayoutProps } from '@/types';
withDefaults(defineProps<PanelLayoutProps>(), {
    navigation: () => [
        { title: 'Panel pracownika', href: dashboard(), icon: LayoutGrid },
        { title: 'Mój profil', href: edit(), icon: UserRound },
    ],
});
</script>
<template>
    <PanelLayout
        title="Panel pracownika"
        :breadcrumbs="breadcrumbs"
        :navigation="navigation"
        ><slot
    /></PanelLayout>
</template>
