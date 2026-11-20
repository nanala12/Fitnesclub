<script setup lang="ts">
import PublicSeo from '@/components/public/PublicSeo.vue';
import PublicHeading from '@/components/public/PublicHeading.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { schedule } from '@/routes/public';
defineProps<{
    slots: {
        id: number;
        weekday: number;
        dayLabel: string;
        name: string;
        description: string;
        startsAt: string;
        endsAt: string;
        instructor: string | null;
    }[];
}>();
</script>
<template>
    <PublicSeo
        title="Grafik zajęć"
        description="Sprawdź opublikowany tygodniowy grafik zajęć ActiveFit."
        :canonical="schedule.url()"
    /><PublicHeading
        eyebrow="Zaplanuj swój ruch"
        title="Grafik zajęć"
        description="Znajdź dzień i godzinę zajęć, które pasują do Twojego rytmu."
    />
    <ul v-if="slots.length" class="grid gap-4 md:grid-cols-2">
        <li
            v-for="slot in slots"
            :key="slot.id"
            class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-6"
        >
            <p class="text-sm font-bold text-primary">
                {{ slot.dayLabel }} · {{ slot.startsAt }}–{{ slot.endsAt }}
            </p>
            <h2 class="text-xl font-bold break-words">{{ slot.name }}</h2>
            <p
                v-if="slot.description"
                class="text-sm leading-relaxed break-words text-muted-foreground"
            >
                {{ slot.description }}
            </p>
            <p v-if="slot.instructor" class="text-sm break-words">
                Prowadzący: {{ slot.instructor }}
            </p>
        </li>
    </ul>
    <EmptyState
        v-else
        title="Grafik będzie dostępny wkrótce"
        description="Nie opublikowano jeszcze terminów zajęć."
    />
</template>
