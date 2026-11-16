<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import { offer } from '@/routes/public';
import type { HeroSlide } from '@/types/public';
const props = defineProps<{ slides: HeroSlide[] }>();
const index = ref(0);
const active = computed(() => props.slides[index.value]);
watch(
    () => props.slides,
    () => {
        index.value = 0;
    },
);
function move(offset: number) {
    if (props.slides.length)
        index.value =
            (index.value + offset + props.slides.length) % props.slides.length;
}
</script>
<template>
    <section
        class="relative isolate overflow-hidden rounded-[24px] bg-sidebar text-sidebar-foreground"
        role="region"
        aria-roledescription="karuzela"
        aria-label="Poznaj ActiveFit"
        @keydown.left.prevent="move(-1)"
        @keydown.right.prevent="move(1)"
    >
        <ContentImage
            v-if="active?.image"
            :src="active.image.src"
            :alt="active.image.alt"
            loading="eager"
            class="absolute! inset-0 -z-20 h-full w-full"
        />
        <div
            class="absolute inset-0 -z-10 bg-gradient-to-r from-[#403934] via-[#403934]/80 to-[#403934]/40"
            aria-hidden="true"
        />
        <div
            class="flex min-h-[420px] flex-col justify-center px-7 py-16 sm:min-h-[470px] sm:px-14 lg:px-20"
        >
            <p class="mb-5 text-xs font-bold tracking-[0.18em] uppercase">
                ActiveFit
            </p>
            <h1
                class="max-w-3xl text-4xl leading-[1.08] font-extrabold tracking-tight sm:text-5xl lg:text-6xl"
            >
                {{ active?.title || 'Miejsce, w którym dobrze się rusza.' }}
            </h1>
            <p
                class="mt-6 max-w-xl text-base leading-relaxed text-sidebar-foreground/90 sm:text-lg"
            >
                {{
                    active?.description ||
                    'Poznaj ofertę klubu, sprawdź aktualności i zaplanuj swoją wizytę.'
                }}
            </p>
            <Link
                :href="offer()"
                class="mt-8 inline-flex min-h-12 w-fit items-center rounded-xl bg-card px-6 font-bold text-foreground focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                ><span
                    >Poznaj naszą ofertę <span aria-hidden="true">→</span></span
                ></Link
            >
        </div>
        <div
            v-if="slides.length > 1"
            class="flex flex-wrap items-center justify-center gap-3 px-4 pb-6"
        >
            <button
                type="button"
                @click="move(-1)"
                aria-label="Poprzedni slajd"
                class="flex size-11 items-center justify-center rounded-full border border-white/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
                <ChevronLeft class="size-5" /></button
            ><button
                v-for="(slide, position) in slides"
                :key="slide.id"
                type="button"
                :aria-label="`Slajd ${position + 1}`"
                :aria-current="index === position ? 'true' : undefined"
                @click="index = position"
                class="flex size-11 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
                <span
                    :class="[
                        'size-2.5 rounded-full',
                        index === position ? 'bg-white' : 'bg-white/40',
                    ]"
                /></button
            ><button
                type="button"
                @click="move(1)"
                aria-label="Następny slajd"
                class="flex size-11 items-center justify-center rounded-full border border-white/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
                <ChevronRight class="size-5" />
            </button>
            <p class="sr-only" role="status">
                Slajd {{ index + 1 }} z {{ slides.length }}
            </p>
        </div>
    </section>
</template>
