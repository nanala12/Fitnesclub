<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import BrandLogo from '@/components/shared/BrandLogo.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
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
import { home, logout } from '@/routes';
import type { PanelLayoutProps } from '@/types';

withDefaults(defineProps<PanelLayoutProps & { title: string }>(), {
    breadcrumbs: () => [],
    navigation: () => [],
});
const page = usePage();
const menuOpen = ref(false);
</script>

<template>
    <div
        class="min-h-dvh bg-background lg:grid lg:grid-cols-[246px_minmax(0,1fr)]"
    >
        <a
            href="#main-content"
            class="sr-only z-[100] rounded-lg bg-card px-4 py-3 text-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >Przejdź do treści</a
        >
        <aside
            class="sticky top-0 hidden h-dvh flex-col bg-sidebar px-3.5 py-7 text-sidebar-foreground lg:flex"
            aria-label="Nawigacja panelu"
        >
            <Link
                :href="home()"
                aria-label="ActiveFit — strona główna"
                class="mx-4 mb-8 inline-flex min-h-11 w-fit items-center rounded-sm"
                ><BrandLogo inverse
            /></Link>
            <p
                class="px-4 pb-3 text-xs font-semibold tracking-widest text-sidebar-foreground/75 uppercase"
            >
                {{ title }}
            </p>
            <nav class="min-h-0 flex-1 overflow-y-auto">
                <NavigationLinks :items="navigation" inverse />
            </nav>
            <div
                v-if="page.props.auth.user"
                class="mt-6 space-y-3 border-t border-white/15 px-4 pt-5"
            >
                <p class="truncate text-sm font-semibold">
                    {{ page.props.auth.user.name }}
                </p>
                <Link
                    :href="logout()"
                    as="button"
                    class="min-h-11 rounded-lg px-2 text-sm hover:bg-white/10 focus-visible:outline-white"
                    data-test="logout-button"
                    >Wyloguj się</Link
                >
            </div>
        </aside>
        <div class="min-w-0">
            <header
                class="flex min-h-[76px] items-center gap-4 border-b border-border bg-card px-4 sm:px-8"
            >
                <Sheet v-model:open="menuOpen">
                    <SheetTrigger as-child
                        ><ActionButton
                            variant="outline"
                            aria-label="Otwórz menu"
                            class="lg:hidden"
                            ><Menu
                                class="size-5"
                                aria-hidden="true" /></ActionButton
                    ></SheetTrigger>
                    <SheetContent
                        side="left"
                        class="w-[min(300px,90vw)] bg-sidebar text-sidebar-foreground"
                    >
                        <SheetHeader class="px-5 pt-8">
                            <SheetTitle class="text-sidebar-foreground">{{
                                title
                            }}</SheetTitle>
                            <SheetDescription class="text-sidebar-foreground/75"
                                >Wybierz stronę swojego
                                panelu.</SheetDescription
                            >
                        </SheetHeader>
                        <nav
                            aria-label="Nawigacja mobilna panelu"
                            class="overflow-y-auto px-3"
                        >
                            <NavigationLinks
                                :items="navigation"
                                inverse
                                @select="menuOpen = false"
                            />
                        </nav>
                        <div
                            v-if="page.props.auth.user"
                            class="mt-auto space-y-3 border-t border-white/15 p-5"
                        >
                            <p class="text-sm break-words">
                                {{ page.props.auth.user.name }}
                            </p>
                            <Link
                                :href="logout()"
                                as="button"
                                class="min-h-11 rounded-lg px-3 text-sm hover:bg-white/10"
                                data-test="mobile-logout-button"
                                >Wyloguj się</Link
                            >
                        </div>
                    </SheetContent>
                </Sheet>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-muted-foreground">{{ title }}</p>
                    <Breadcrumbs
                        v-if="breadcrumbs.length"
                        :breadcrumbs="breadcrumbs"
                    />
                </div>
                <Link
                    :href="home()"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-muted-foreground hover:text-foreground"
                    >Strona klubu</Link
                >
            </header>
            <main id="main-content" tabindex="-1" class="min-w-0 p-4 sm:p-8">
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
