<script setup lang="ts">
import { UserRound } from '@lucide/vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { edit } from '@/routes/profile';
import { index as orders } from '@/routes/orders';
import type { PanelLayoutProps } from '@/types';
withDefaults(defineProps<PanelLayoutProps>(), {
    navigation: () => [
        { title: 'Mój profil', href: edit(), icon: UserRound },
        { title: 'Moje zamówienia', href: orders(), icon: UserRound },
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
