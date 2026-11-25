<script setup lang="ts">
import { LayoutGrid, ShieldCheck, UserRound } from '@lucide/vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { dashboard } from '@/routes';
import { edit as profile } from '@/routes/profile';
import { edit as security } from '@/routes/security';
import { index as orders } from '@/routes/orders';
import type { PanelLayoutProps } from '@/types';
withDefaults(defineProps<PanelLayoutProps>(), {
    navigation: () => [
        { title: 'Moje konto', href: dashboard(), icon: LayoutGrid },
        { title: 'Moje zamówienia', href: orders(), icon: LayoutGrid },
        { title: 'Profil', href: profile(), icon: UserRound },
        { title: 'Bezpieczeństwo', href: security(), icon: ShieldCheck },
    ],
});
</script>

<template>
    <PanelLayout
        title="Moje konto"
        :breadcrumbs="breadcrumbs"
        :navigation="navigation"
        ><slot
    /></PanelLayout>
</template>
