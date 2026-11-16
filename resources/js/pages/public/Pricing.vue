<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PublicHeading from '@/components/public/PublicHeading.vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { pricing, contact } from '@/routes/public';
defineProps<{ plans: never[] }>();
</script>
<template>
    <PublicSeo
        title="Cennik"
        description="Informacje o ofercie i cenach karnetów ActiveFit."
        :canonical="pricing.url()"
    /><PublicHeading
        eyebrow="ActiveFit / Cennik"
        title="Twój trening. Twój wybór."
        description="Tutaj znajdziesz aktualną ofertę karnetów udostępnionych przez klub."
    /><EmptyState
        title="Cennik będzie dostępny wkrótce"
        description="Nie opublikowano jeszcze oferty. Sprawdź informacje kontaktowe, aby zapytać o szczegóły."
        ><Button as-child variant="outline"
            ><Link :href="contact()">Kontakt z klubem</Link></Button
        ></EmptyState
    >
</template>
