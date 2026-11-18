<script setup lang="ts">
import {
    ClipboardList,
    LayoutGrid,
    UserRound,
    Users,
    Images,
    PanelsTopLeft,
    Newspaper,
} from '@lucide/vue';
import NewsPostController from '@/actions/App/Http/Controllers/Admin/Cms/NewsPostController';
import HomepageContentController from '@/actions/App/Http/Controllers/Admin/Cms/HomepageContentController';
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
        {
            title: 'Kafelki oferty',
            href: HomepageContentController.index('kafelki'),
            icon: PanelsTopLeft,
        },
        {
            title: 'Karuzela',
            href: HomepageContentController.index('karuzela'),
            icon: Images,
        },
        {
            title: 'Aktualności',
            href: NewsPostController.index(),
            icon: Newspaper,
        },
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
