<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PriceListController from '@/actions/App/Http/Controllers/Admin/PriceListController';
import MembershipPlanController from '@/actions/App/Http/Controllers/Admin/MembershipPlanController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import type { AdminPriceItem, PricePagination } from '@/types/pricing';

const props = defineProps<{
    categories: PricePagination;
    services: PricePagination;
}>();
const form = useForm({
    revision: 0,
    item: '',
    is_published: false,
    page: 1,
    category_page: 1,
});
const toggled = ref<string | null>(null);
const selected = ref<{
    type: 'kategorie' | 'uslugi';
    item: AdminPriceItem;
} | null>(null);
const confirmOpen = ref(false);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
function remove() {
    if (!selected.value) return;
    form.revision = selected.value.item.revision;
    form.delete(
        PriceListController.destroy.url({
            type: selected.value.type,
            item: selected.value.item.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
            },
        },
    );
}
function select(type: 'kategorie' | 'uslugi', item: AdminPriceItem) {
    selected.value = { type, item };
    form.clearErrors();
    confirmOpen.value = true;
}
function visibility(type: 'kategorie' | 'uslugi', item: AdminPriceItem) {
    form.clearErrors();
    form.revision = item.revision;
    form.is_published = !item.is_published;
    form.page = props.services.current_page;
    form.category_page = props.categories.current_page;
    toggled.value = `${type}-${item.id}`;
    form.patch(PriceListController.visibility.url({ type, item: item.id }), {
        preserveScroll: true,
        onFinish: () => {
            toggled.value = null;
        },
    });
}
</script>
<template>
    <Head title="Kategorie i usługi cennika" />
    <div class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl sm:text-4xl">
                    Kategorie i usługi cennika
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Dodaj kategorie i przypisz do nich karnety oraz usługi.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="MembershipPlanController.index()"
                    >Karnety i wyróżnienia</Link
                ></Button
            >
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link :href="PriceListController.index()" class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <section aria-labelledby="categories-title" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="categories-title" class="font-heading text-2xl">
                    Kategorie filtrów
                </h2>
                <Button as-child
                    ><Link :href="PriceListController.create('kategorie')"
                        >Dodaj kategorię</Link
                    ></Button
                >
            </div>
            <article
                v-for="category in categories.data"
                :key="category.id"
                class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h3 class="text-lg font-bold break-words">
                    {{ category.name }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ category.is_published ? 'Widoczna' : 'Ukryta' }} ·
                    Kolejność: {{ category.sort_order }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline"
                        ><Link
                            :href="
                                PriceListController.edit({
                                    type: 'kategorie',
                                    item: category.id,
                                })
                            "
                            >Edytuj</Link
                        ></Button
                    ><ActionButton
                        variant="outline"
                        :disabled="form.processing"
                        :loading="
                            form.processing &&
                            toggled === `kategorie-${category.id}`
                        "
                        :aria-label="`${category.is_published ? 'Ukryj' : 'Pokaż w cenniku'} kategorię ${category.name}`"
                        @click="visibility('kategorie', category)"
                        >{{
                            category.is_published ? 'Ukryj' : 'Pokaż w cenniku'
                        }}</ActionButton
                    ><ActionButton
                        v-if="category.key !== 'gym'"
                        variant="outline"
                        :disabled="form.processing"
                        @click="select('kategorie', category)"
                        >Usuń</ActionButton
                    >
                </div>
            </article>
            <Pagination :links="categories.links" />
        </section>
        <section aria-labelledby="services-title" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="services-title" class="font-heading text-2xl">
                    Usługi w cenniku
                </h2>
                <Button as-child
                    ><Link :href="PriceListController.create('uslugi')"
                        >Dodaj usługę</Link
                    ></Button
                >
            </div>
            <EmptyState
                v-if="services.total === 0"
                title="Nie ma jeszcze usług"
                description="Wybierz kategorię i dodaj nazwy oraz rzeczywiste ceny usług klubu. Ceny karnetów są pobierane z planów i nie trzeba ich wpisywać ponownie."
            />
            <article
                v-for="service in services.data"
                :key="service.id"
                class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h3 class="text-lg font-bold break-words">
                        {{ service.name }}
                    </h3>
                    <p class="font-bold break-words">
                        {{ service.priceLabel }}
                    </p>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ service.categoryLabel }} ·
                    {{
                        service.is_published
                            ? service.categoryPublished
                                ? 'Widoczna'
                                : 'Ukryta przez kategorię'
                            : 'Ukryta'
                    }}
                    · Kolejność: {{ service.sort_order }}
                </p>
                <p
                    v-if="service.is_published && !service.categoryPublished"
                    class="text-sm text-muted-foreground"
                >
                    Pokaż także kategorię, aby usługa była widoczna w cenniku.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline"
                        ><Link
                            :href="
                                PriceListController.edit({
                                    type: 'uslugi',
                                    item: service.id,
                                })
                            "
                            >Edytuj</Link
                        ></Button
                    ><ActionButton
                        variant="outline"
                        :disabled="form.processing"
                        :loading="
                            form.processing &&
                            toggled === `uslugi-${service.id}`
                        "
                        :aria-label="`${service.is_published ? 'Ukryj' : 'Pokaż w cenniku'} usługę ${service.name}`"
                        @click="visibility('uslugi', service)"
                        >{{
                            service.is_published ? 'Ukryj' : 'Pokaż w cenniku'
                        }}</ActionButton
                    ><ActionButton
                        variant="outline"
                        :disabled="form.processing"
                        @click="select('uslugi', service)"
                        >Usuń</ActionButton
                    >
                </div>
            </article>
            <Pagination :links="services.links" />
        </section>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Usunąć pozycję cennika?"
            :description="`Pozycja „${selected?.item.name ?? ''}” zostanie usunięta. Kategoria musi być pusta; możesz też ją ukryć.`"
            confirm-label="Usuń pozycję"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
