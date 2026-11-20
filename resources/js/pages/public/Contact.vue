<script setup lang="ts">
import PublicSeo from '@/components/public/PublicSeo.vue';
import PublicHeading from '@/components/public/PublicHeading.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { contact } from '@/routes/public';
import type { ClubInfo, OpeningHour } from '@/types/public';
defineProps<{ club: ClubInfo | null; openingHours: OpeningHour[] }>();
</script>
<template>
    <PublicSeo
        title="Kontakt"
        description="Dane kontaktowe, lokalizacja i godziny otwarcia ActiveFit."
        :canonical="contact.url()"
    /><PublicHeading
        eyebrow="Jesteśmy dla Ciebie"
        title="Skontaktuj się z nami."
        description="Sprawdź dane klubu i godziny otwarcia przed swoją wizytą."
    />
    <div class="grid gap-6 md:grid-cols-2">
        <section
            class="min-w-0 space-y-5 rounded-2xl border border-border bg-card p-6 sm:p-8"
        >
            <h2 class="text-2xl font-bold">Kontakt z klubem</h2>
            <template v-if="club"
                ><p v-if="club.name" class="font-semibold break-words">
                    {{ club.name }}
                </p>
                <address
                    v-if="club.address"
                    class="leading-relaxed break-words not-italic"
                >
                    {{ club.address }}<br />{{ club.postalCode }}
                    {{ club.city }}
                </address>
                <p v-if="club.phone">
                    <a
                        v-if="club.phoneHref"
                        :href="club.phoneHref"
                        class="inline-flex min-h-11 items-center font-bold text-primary"
                        >{{ club.phone }}</a
                    ><span v-else>{{ club.phone }}</span>
                </p>
                <p v-if="club.email">
                    <a
                        :href="`mailto:${club.email}`"
                        class="inline-flex min-h-11 items-center font-bold break-all text-primary"
                        >{{ club.email }}</a
                    >
                </p>
                <a
                    v-if="club.mapUrl"
                    :href="club.mapUrl"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-11 items-center font-bold text-primary"
                    >Otwórz mapę ↗</a
                ></template
            ><EmptyState
                v-if="!club || (!club.email && !club.phone && !club.address)"
                title="Dane kontaktowe wkrótce"
                description="Informacje kontaktowe nie zostały jeszcze uzupełnione."
            />
        </section>
        <section
            class="min-w-0 space-y-5 rounded-2xl border border-border bg-card p-6 sm:p-8"
        >
            <h2 class="text-2xl font-bold">Godziny otwarcia</h2>
            <dl v-if="openingHours.length" class="space-y-4">
                <div
                    v-for="day in openingHours"
                    :key="day.weekday"
                    class="flex flex-wrap justify-between gap-3 border-b border-border pb-3"
                >
                    <dt>{{ day.label }}</dt>
                    <dd class="font-semibold">
                        {{
                            day.closed
                                ? 'Nieczynne'
                                : day.opensAt && day.closesAt
                                  ? `${day.opensAt}–${day.closesAt}`
                                  : 'Brak informacji'
                        }}
                    </dd>
                </div>
            </dl>
            <EmptyState
                v-else
                title="Godziny będą dostępne wkrótce"
                description="Nie podano jeszcze godzin otwarcia klubu."
            />
        </section>
    </div>
</template>
