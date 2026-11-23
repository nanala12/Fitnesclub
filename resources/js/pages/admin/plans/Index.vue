<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MembershipPlanController from '@/actions/App/Http/Controllers/Admin/MembershipPlanController';
import PriceListController from '@/actions/App/Http/Controllers/Admin/PriceListController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import type { PaginationLink } from '@/types';
import type { AdminMembershipPlan } from '@/types/plans';

const props = defineProps<{
    plans: {
        data: AdminMembershipPlan[];
        total: number;
        current_page: number;
        links: PaginationLink[];
    };
    featuredCount: number;
}>();
const form = useForm({ revision: 0, page: 1, plan: '', is_public: false });
const toggled = ref<number | null>(null);
const selected = ref<AdminMembershipPlan | null>(null);
const confirmOpen = ref(false);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
function visibility(plan: AdminMembershipPlan) {
    form.clearErrors();
    form.revision = plan.revision;
    form.page = props.plans.current_page;
    form.is_public = !plan.is_public;
    toggled.value = plan.id;
    form.patch(MembershipPlanController.visibility.url(plan.id), {
        preserveScroll: true,
        onFinish: () => {
            toggled.value = null;
        },
    });
}
function remove() {
    if (!selected.value) return;
    form.clearErrors();
    form.revision = selected.value.revision;
    form.page = props.plans.current_page;
    form.delete(MembershipPlanController.destroy.url(selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}
</script>
<template>
    <Head title="Karnety i cennik" />
    <div class="space-y-6">
        <Link
            :href="PriceListController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >Kategorie i usługi cennika →</Link
        >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl sm:text-4xl">
                    Karnety i cennik
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Zarządzaj cenami, warunkami i widocznością planów.
                    Wyróżnione: {{ featuredCount }}/3.
                </p>
            </div>
            <Button as-child
                ><Link :href="MembershipPlanController.create()"
                    >Dodaj plan karnetu</Link
                ></Button
            >
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="
                    MembershipPlanController.index({
                        query: { page: plans.current_page },
                    })
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <EmptyState
            v-if="plans.total === 0"
            title="Nie ma jeszcze planów karnetów"
            description="Dodaj pierwszy plan. Domyślnie pozostanie niedostępny i ukryty."
        />
        <div v-else class="space-y-4" :aria-busy="form.processing">
            <p class="text-sm text-muted-foreground">
                Planów: {{ plans.total }}
            </p>
            <article
                v-for="plan in plans.data"
                :key="plan.id"
                class="min-w-0 space-y-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="min-w-0 text-xl font-bold break-words">
                        {{ plan.name }}
                    </h2>
                    <p class="font-heading text-2xl break-words">
                        {{ plan.priceLabel }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-muted px-3 py-1">{{
                        plan.is_active ? 'Dostępny' : 'Niedostępny'
                    }}</span>
                    <span class="rounded-full bg-muted px-3 py-1">{{
                        plan.is_public
                            ? 'Widoczny w cenniku'
                            : 'Ukryty w cenniku'
                    }}</span>
                    <span
                        v-if="plan.is_featured"
                        class="rounded-full bg-secondary px-3 py-1"
                        >Wyróżniony</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        plan.kind === 'time'
                            ? 'Karnet czasowy'
                            : `Limit wejść: ${plan.included_entries}`
                    }}<span v-if="plan.kind === 'time' && plan.duration_months">
                        · Ważność w miesiącach: {{ plan.duration_months }}</span
                    >
                    · Kolejność: {{ plan.sort_order }}
                </p>
                <p
                    v-if="plan.description"
                    class="line-clamp-3 text-sm break-words whitespace-pre-line text-muted-foreground"
                >
                    {{ plan.description }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline"
                        ><Link :href="MembershipPlanController.edit(plan.id)"
                            >Edytuj</Link
                        ></Button
                    >
                    <ActionButton
                        variant="outline"
                        :disabled="form.processing"
                        :loading="form.processing && toggled === plan.id"
                        loading-text="Zapisywanie…"
                        :aria-label="`${plan.is_public ? 'Ukryj w cenniku' : 'Pokaż w cenniku'}: ${plan.name}`"
                        @click="visibility(plan)"
                        >{{
                            plan.is_public
                                ? 'Ukryj w cenniku'
                                : 'Pokaż w cenniku'
                        }}</ActionButton
                    >
                    <ActionButton
                        v-if="!plan.hasHistory"
                        variant="outline"
                        :disabled="form.processing"
                        @click="
                            selected = plan;
                            form.clearErrors();
                            confirmOpen = true;
                        "
                        >Usuń</ActionButton
                    >
                </div>
                <p v-if="plan.hasHistory" class="text-xs text-muted-foreground">
                    Plan ma historię zakupów. Możesz go edytować lub wyłączyć w
                    formularzu.
                </p>
            </article>
            <Pagination :links="plans.links" />
        </div>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Usunąć plan karnetu?"
            :description="`Plan „${selected?.name ?? ''}” zostanie usunięty. Można usuwać wyłącznie plany bez historii zakupów.`"
            confirm-label="Usuń plan"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
