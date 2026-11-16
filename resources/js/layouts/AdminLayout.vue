<script setup lang="ts">
import { ClipboardList, LayoutGrid, UserRound, Users } from '@lucide/vue';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as employees } from '@/routes/admin/employees';
import { index as audit } from '@/routes/admin/audit';
import { edit } from '@/routes/profile';
import type { PanelLayoutProps } from '@/types';
withDefaults(defineProps<PanelLayoutProps>(), {
    navigation: () => [
        {
            title: 'Panel administratora',
            href: adminDashboard(),
            icon: LayoutGrid,
        },
        { title: 'Pracownicy', href: employees(), icon: Users },
        { title: 'Historia zmian', href: audit(), icon: ClipboardList },
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
