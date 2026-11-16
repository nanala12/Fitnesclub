<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrandLogo from '@/components/shared/BrandLogo.vue';
import { home } from '@/routes';
withDefaults(defineProps<{ panelLabel?: string }>(), {
    panelLabel: 'Panel pracownika',
});
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <a
            href="#main-content"
            class="sr-only z-50 rounded-lg bg-card p-3 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >Przejdź do treści</a
        >
        <header class="border-b border-border bg-card">
            <div
                class="mx-auto flex h-[67px] w-[calc(100%-30px)] max-w-[1120px] items-center justify-between gap-4 min-[601px]:h-[78px] min-[601px]:w-[calc(100%-36px)]"
            >
                <Link
                    :href="home()"
                    aria-label="ActiveFit — strona główna"
                    class="inline-flex min-h-11 items-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    ><BrandLogo
                /></Link>
                <span
                    class="text-xs text-muted-foreground min-[601px]:text-[13px]"
                    >{{ panelLabel }}</span
                >
            </div>
        </header>
        <main
            id="main-content"
            tabindex="-1"
            class="grid flex-1 place-items-center px-[15px] pt-[30px] pb-11 min-[601px]:px-[18px] min-[601px]:pt-16 min-[601px]:pb-[78px]"
        >
            <slot />
        </main>
        <footer
            class="bg-sidebar px-[18px] py-6 text-center text-xs leading-[1.55] text-sidebar-foreground/85"
        >
            <strong class="text-sidebar-foreground">ActiveFit Pleszew</strong> ·
            ul. Traugutta 30, 63-300 Pleszew<br />
            <a
                href="tel:+48693388508"
                class="inline-flex min-h-11 items-center hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                >693 388 508</a
            >
            ·
            <a
                href="mailto:magda@activefit.pl"
                class="inline-flex min-h-11 items-center hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                >magda@activefit.pl</a
            >
        </footer>
    </div>
</template>
