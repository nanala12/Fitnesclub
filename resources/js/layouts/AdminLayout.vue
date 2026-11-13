<script setup lang="ts">
import { LayoutGrid, UserRound, Users } from '@lucide/vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as employees } from '@/routes/admin/employees';
import { dashboard as staffDashboard } from '@/routes/staff';
import { edit } from '@/routes/profile';
import type { PanelLayoutProps } from '@/types';
withDefaults(defineProps<PanelLayoutProps>(), {
    navigation: () => [
        {
            title: 'Panel administratora',
            href: adminDashboard(),
            icon: LayoutGrid,
        },
        { title: 'Panel pracownika', href: staffDashboard(), icon: Users },
        { title: 'Pracownicy', href: employees(), icon: Users },
        { title: 'Mój profil', href: edit(), icon: UserRound },
    ],
});
</script>
<template>
    <PanelLayout
        title="Panel administratora"
        :breadcrumbs="breadcrumbs"
        :navigation="navigation"
        ><slot
    /></PanelLayout>
</template>
