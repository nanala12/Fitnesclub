<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HeroCarousel from '@/components/public/HeroCarousel.vue';
import OfferTiles from '@/components/public/OfferTiles.vue';
import NewsCards from '@/components/public/NewsCards.vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { pricing, contact } from '@/routes/public';
import { index as newsIndex } from '@/routes/public/news';
import type { HeroSlide, OfferTile, NewsCard } from '@/types/public';
defineProps<{
    canRegister: boolean;
    slides: HeroSlide[];
    tiles: OfferTile[];
    news: NewsCard[];
}>();
</script>
<template>
    <PublicSeo
        title="ActiveFit — ruch w Twoim rytmie"
        description="Poznaj ofertę ActiveFit, sprawdź aktualności, grafik i informacje o klubie."
        :canonical="home.url()"
    />
    <div class="space-y-16 sm:space-y-20">
        <HeroCarousel :slides="slides" />
        <section id="oferta" aria-labelledby="offer-title" class="space-y-7">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <p
                        class="mb-3 text-xs font-bold tracking-widest text-primary uppercase"
                    >
                        Odkryj ActiveFit
                    </p>
                    <h2
                        id="offer-title"
                        class="text-3xl font-extrabold tracking-tight sm:text-4xl"
                    >
                        Znajdź aktywność dla siebie.
                    </h2>
                </div>
                <p class="max-w-md text-muted-foreground">
                    Sprawdź, jakie formy aktywności oferuje nasz klub.
                </p>
            </div>
            <OfferTiles :tiles="tiles" />
        </section>
        <section
            aria-labelledby="pricing-title"
            class="rounded-3xl bg-secondary p-6 sm:p-10"
        >
            <div class="mb-7 flex flex-wrap items-center justify-between gap-5">
                <div>
                    <p
                        class="mb-3 text-xs font-bold tracking-widest text-primary uppercase"
                    >
                        Karnety
                    </p>
                    <h2
                        id="pricing-title"
                        class="text-3xl font-extrabold tracking-tight"
                    >
                        Twój trening. Twój wybór.
                    </h2>
                </div>
                <Button as-child variant="outline"
                    ><Link :href="pricing()">Sprawdź cennik →</Link></Button
                >
            </div>
            <EmptyState
                title="Cennik będzie dostępny wkrótce"
                description="Aktualne plany pojawią się tutaj po udostępnieniu oferty."
            />
        </section>
        <section aria-labelledby="news-title" class="space-y-7">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h2
                    id="news-title"
                    class="text-3xl font-extrabold tracking-tight"
                >
                    Co u nas słychać?
                </h2>
                <Link
                    :href="newsIndex()"
                    class="inline-flex min-h-11 items-center font-bold text-primary"
                    >Wszystkie aktualności →</Link
                >
            </div>
            <NewsCards :posts="news" />
        </section>
        <section
            class="flex flex-wrap items-center justify-between gap-6 rounded-3xl border border-border bg-card p-7 sm:p-10"
        >
            <div>
                <h2 class="text-3xl font-extrabold tracking-tight">
                    Masz pytania? Porozmawiajmy.
                </h2>
                <p class="mt-3 text-muted-foreground">
                    Sprawdź informacje o klubie i zaplanuj swoją wizytę.
                </p>
            </div>
            <Button as-child
                ><Link :href="contact()">Skontaktuj się z nami →</Link></Button
            >
        </section>
    </div>
</template>
