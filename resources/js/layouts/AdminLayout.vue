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
import ContactSettingsController from '@/actions/App/Http/Controllers/Admin/Cms/ContactSettingsController';
import ClassScheduleController from '@/actions/App/Http/Controllers/Admin/Cms/ClassScheduleController';
import RegulationController from '@/actions/App/Http/Controllers/Admin/Cms/RegulationController';
import GalleryController from '@/actions/App/Http/Controllers/Admin/Cms/GalleryController';
import NewsPostController from '@/actions/App/Http/Controllers/Admin/Cms/NewsPostController';
import HomepageContentController from '@/actions/App/Http/Controllers/Admin/Cms/HomepageContentController';
import MembershipPlanController from '@/actions/App/Http/Controllers/Admin/MembershipPlanController';
import PriceListController from '@/actions/App/Http/Controllers/Admin/PriceListController';
import PanelLayout from '@/layouts/PanelLayout.vue';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as employees } from '@/routes/admin/employees';
import { index as audit } from '@/routes/admin/audit';
import { index as deliveries } from '@/routes/admin/deliveries';
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
            title: 'Kategorie i usługi cennika',
            href: PriceListController.index(),
            icon: ClipboardList,
        },
        {
            title: 'Karnety i cennik',
            href: MembershipPlanController.index(),
            icon: ClipboardList,
        },
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
        { title: 'Galeria', href: GalleryController.index(), icon: Images },
        {
            title: 'Regulamin',
            href: RegulationController.index(),
            icon: ClipboardList,
        },
        {
            title: 'Kontakt i godziny',
            href: ContactSettingsController.index(),
            icon: ClipboardList,
        },
        {
            title: 'Grafik zajęć',
            href: ClassScheduleController.index(),
            icon: ClipboardList,
        },
        { title: 'Historia zmian', href: audit(), icon: ClipboardList },
        { title: 'Wysyłki PDF', href: deliveries(), icon: ClipboardList },
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
