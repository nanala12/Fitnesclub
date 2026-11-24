<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/checkout';
import type { PublicMembershipPlan } from '@/types/plans';
defineProps<{ plans: PublicMembershipPlan[] }>();
</script>
<template>
    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="plan in plans"
            :key="plan.id"
            class="flex min-w-0 flex-col gap-5 rounded-2xl border border-border bg-card p-6"
        >
            <p class="text-xs font-bold tracking-widest text-primary uppercase">
                {{
                    plan.kind === 'time'
                        ? 'Karnet czasowy'
                        : 'Karnet z limitem wejść'
                }}
            </p>
            <h3 class="text-2xl font-bold break-words">{{ plan.name }}</h3>
            <p class="font-heading text-3xl font-bold break-words">
                {{ plan.priceLabel }}
            </p>
            <p
                v-if="plan.description"
                class="text-sm break-words whitespace-pre-line text-muted-foreground"
            >
                {{ plan.description }}
            </p>
            <dl class="grid gap-3 text-sm">
                <div v-if="plan.includedEntries !== null">
                    <dt class="text-muted-foreground">Liczba wejść</dt>
                    <dd class="font-semibold">{{ plan.includedEntries }}</dd>
                </div>
                <div
                    v-if="plan.kind === 'time' && plan.durationMonths !== null"
                >
                    <dt class="text-muted-foreground">Ważność w miesiącach</dt>
                    <dd class="font-semibold">{{ plan.durationMonths }}</dd>
                </div>
            </dl>
            <p
                v-if="plan.kind === 'time'"
                class="text-xs text-muted-foreground"
            >
                Ważność rozpoczyna się przy pierwszej wizycie w klubie.
            </p>
            <p v-else class="text-xs text-muted-foreground">
                Bez ograniczenia czasowego — do wykorzystania wejść.
            </p>
            <Button as-child variant="outline" class="mt-auto"
                ><Link :href="create({ query: { plan: plan.id } })"
                    >Kup karnet<span class="sr-only">
                        {{ plan.name }}</span
                    ></Link
                ></Button
            >
        </article>
    </div>
</template>
