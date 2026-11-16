<script setup lang="ts">
import PublicHeading from '@/components/public/PublicHeading.vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import OfferTiles from '@/components/public/OfferTiles.vue';
import { offer } from '@/routes/public';
import type { OfferTile } from '@/types/public';
defineProps<{ tiles: OfferTile[] }>();
</script>
<template>
    <PublicSeo
        title="Oferta"
        description="Poznaj dostępne formy aktywności w ActiveFit."
        :canonical="offer.url()"
    /><PublicHeading
        eyebrow="Odkryj ActiveFit"
        title="Znajdź aktywność dla siebie."
        description="Poznaj ofertę klubu i wybierz formę ruchu dopasowaną do swoich potrzeb."
    /><OfferTiles :tiles="tiles" />
</template>
