<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrandLogo from '@/components/shared/BrandLogo.vue';
import { contact, regulations } from '@/routes/public';
import type { ClubInfo, OpeningHour } from '@/types/public';
withDefaults(
    defineProps<{
        club?: ClubInfo | null;
        openingHours?: OpeningHour[];
        compact?: boolean;
    }>(),
    { club: null, openingHours: () => [], compact: false },
);
</script>
<template>
    <footer class="bg-sidebar px-4 py-8 text-sidebar-foreground">
        <div
            :class="[
                'mx-auto max-w-[1130px] gap-8',
                compact
                    ? 'text-center text-sm'
                    : 'grid sm:grid-cols-2 lg:grid-cols-3',
            ]"
        >
            <div class="space-y-4">
                <BrandLogo inverse />
                <p v-if="club?.name" class="font-semibold">{{ club.name }}</p>
                <div v-if="!compact" class="flex flex-wrap gap-5 text-sm">
                    <Link
                        :href="contact()"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >Kontakt</Link
                    ><Link
                        :href="regulations()"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >Regulamin</Link
                    >
                </div>
            </div>
            <div v-if="!compact" class="space-y-3">
                <h2 class="text-xs font-bold tracking-widest uppercase">
                    Godziny otwarcia
                </h2>
                <ul v-if="openingHours.length" class="space-y-2 text-sm">
                    <li v-for="day in openingHours" :key="day.weekday">
                        {{ day.label }}:
                        {{
                            day.closed
                                ? 'Nieczynne'
                                : day.opensAt && day.closesAt
                                  ? `${day.opensAt}–${day.closesAt}`
                                  : 'Brak informacji o godzinach'
                        }}
                    </li>
                </ul>
                <p v-else class="text-sm text-sidebar-foreground/80">
                    Godziny otwarcia zostaną wkrótce uzupełnione.
                </p>
            </div>
            <div :class="['space-y-2 text-sm', compact && 'mt-4']">
                <h2
                    v-if="!compact"
                    class="text-xs font-bold tracking-widest uppercase"
                >
                    Kontakt
                </h2>
                <p v-if="club?.address">
                    {{ club.address }}<br />{{ club.postalCode }}
                    {{ club.city }}
                </p>
                <p v-if="club?.phone">
                    <a
                        v-if="club.phoneHref"
                        :href="club.phoneHref"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >{{ club.phone }}</a
                    ><span v-else>{{ club.phone }}</span>
                </p>
                <p v-if="club?.email">
                    <a
                        :href="`mailto:${club.email}`"
                        class="inline-flex min-h-11 items-center break-all hover:underline"
                        >{{ club.email }}</a
                    >
                </p>
                <p
                    v-if="!club?.email && !club?.phone && !club?.address"
                    class="text-sidebar-foreground/80"
                >
                    Dane kontaktowe zostaną wkrótce uzupełnione.
                </p>
            </div>
        </div>
    </footer>
</template>
