<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import BrandLogo from '@/components/shared/BrandLogo.vue';
import NavigationLinks from '@/components/shared/NavigationLinks.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, home, login } from '@/routes';
import type { NavItem } from '@/types';
import type { ClubInfo, OpeningHour } from '@/types/public';
import ClubFooter from '@/components/public/ClubFooter.vue';
import {
    offer,
    pricing,
    schedule,
    gallery,
    contact,
    regulations,
} from '@/routes/public';
import { index as news } from '@/routes/public/news';
withDefaults(
    defineProps<{
        navigation?: NavItem[];
        club?: ClubInfo | null;
        openingHours?: OpeningHour[];
    }>(),
    {
        navigation: () => [
            { title: 'Start', href: home() },
            { title: 'Oferta', href: offer() },
            { title: 'Cennik', href: pricing() },
            { title: 'Grafik', href: schedule() },
            { title: 'Aktualności', href: news() },
            { title: 'Galeria', href: gallery() },
            { title: 'Regulamin', href: regulations() },
            { title: 'Kontakt', href: contact() },
        ],
    },
);
const page = usePage();
const menuOpen = ref(false);
const accountLink = computed(() =>
    page.props.auth.user ? dashboard() : login(),
);
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-background">
        <a
            href="#main-content"
            class="sr-only z-[100] rounded-lg bg-card px-4 py-3 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >Przejdź do treści</a
        >
        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex min-h-[66px] max-w-[1170px] items-center gap-3 px-4 sm:min-h-[76px] sm:gap-4 sm:px-5"
            >
                <Link
                    :href="home()"
                    aria-label="ActiveFit — strona główna"
                    class="inline-flex min-h-11 items-center rounded-sm"
                    ><BrandLogo
                /></Link>
                <nav
                    aria-label="Nawigacja główna"
                    class="ml-auto hidden xl:block"
                >
                    <NavigationLinks :items="navigation" horizontal />
                </nav>
                <Link
                    :href="accountLink"
                    :aria-label="
                        page.props.auth.user ? 'Moje konto' : 'Zaloguj się'
                    "
                    class="ml-auto inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-primary px-3 text-sm font-semibold whitespace-nowrap text-primary-foreground hover:bg-primary/90 sm:px-4 xl:ml-4"
                >
                    <span class="sm:hidden">{{
                        page.props.auth.user ? 'Konto' : 'Zaloguj'
                    }}</span>
                    <span class="hidden sm:inline">{{
                        page.props.auth.user ? 'Moje konto' : 'Zaloguj się'
                    }}</span>
                </Link>
                <Sheet v-model:open="menuOpen">
                    <SheetTrigger as-child
                        ><ActionButton
                            variant="outline"
                            aria-label="Otwórz menu"
                            class="size-11 shrink-0 px-0 has-[>svg]:px-0 xl:hidden"
                            ><Menu
                                class="size-5"
                                aria-hidden="true" /></ActionButton
                    ></SheetTrigger>
                    <SheetContent
                        side="right"
                        class="w-[min(300px,90vw)] bg-card"
                    >
                        <SheetHeader
                            ><SheetTitle>Menu ActiveFit</SheetTitle
                            ><SheetDescription
                                >Wybierz stronę klubu.</SheetDescription
                            ></SheetHeader
                        >
                        <nav
                            aria-label="Nawigacja mobilna"
                            class="min-h-0 flex-1 overflow-y-auto px-3"
                        >
                            <NavigationLinks
                                :items="navigation"
                                @select="menuOpen = false"
                            />
                        </nav>
                    </SheetContent>
                </Sheet>
            </div>
        </header>
        <main
            id="main-content"
            tabindex="-1"
            class="mx-auto w-full max-w-[1170px] flex-1 px-4 py-10 sm:px-5 sm:py-16"
        >
            <slot />
        </main>
        <ClubFooter :club="club" :opening-hours="openingHours" />
        <Toaster />
    </div>
</template>
