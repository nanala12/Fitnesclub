<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Images, LayoutGrid, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import HomepageContentController from '@/actions/App/Http/Controllers/Admin/Cms/HomepageContentController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import ContentImage from '@/components/shared/ContentImage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import type { PaginationLink } from '@/types';
import type { HomepageItem, HomepageSection } from '@/types/homepage';

const props = defineProps<{
    section: HomepageSection;
    revision: number;
    items: {
        data: HomepageItem[];
        total: number;
        current_page: number;
        per_page: number;
        links: PaginationLink[];
    };
}>();
const isTiles = computed(() => props.section === 'kafelki');
const heading = computed(() =>
    isTiles.value ? 'Kafelki oferty' : 'Karuzela zdjęć',
);
const form = useForm({
    revision: props.revision,
    page: 1,
    direction: 'up',
    is_published: false,
    current_media_id: null as number | null,
    image: null,
});
const confirmOpen = ref(false);
const selected = ref<HomepageItem | null>(null);
const activeId = ref<number | null>(null);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
const offset = computed(
    () => (props.items.current_page - 1) * props.items.per_page,
);
function prepare(item: HomepageItem) {
    form.clearErrors();
    form.revision = props.revision;
    form.page = props.items.current_page;
    activeId.value = item.id;
}
function move(item: HomepageItem, direction: 'up' | 'down') {
    prepare(item);
    form.direction = direction;
    form.patch(
        HomepageContentController.move.url({
            section: props.section,
            item: item.id,
        }),
        { preserveScroll: true },
    );
}
function visibility(item: HomepageItem) {
    prepare(item);
    form.is_published = !item.is_published;
    form.patch(
        HomepageContentController.visibility.url({
            section: props.section,
            item: item.id,
        }),
        { preserveScroll: true },
    );
}
function confirm(item: HomepageItem) {
    form.clearErrors();
    selected.value = item;
    confirmOpen.value = true;
}
function remove() {
    if (!selected.value) return;
    prepare(selected.value);
    form.current_media_id = selected.value.image?.id ?? null;
    form.delete(
        HomepageContentController.destroy.url({
            section: props.section,
            item: selected.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
                selected.value = null;
            },
        },
    );
}
</script>
<template>
    <Head :title="heading" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Zarządzanie stroną / Start
                </p>
                <h1 class="font-heading text-3xl sm:text-4xl">{{ heading }}</h1>
                <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                    {{
                        isTiles
                            ? 'Zarządzaj ofertą widoczną na stronie głównej i w zakładce Oferta.'
                            : 'Zarządzaj zdjęciami, nagłówkami i opisami karuzeli na stronie głównej.'
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="home()">Podgląd strony</Link></Button
                ><Button as-child
                    ><Link :href="HomepageContentController.create(section)"
                        ><Plus class="size-4" aria-hidden="true" />{{
                            isTiles ? 'Dodaj kafelek' : 'Dodaj slajd'
                        }}</Link
                    ></Button
                >
            </div>
        </div>
        <nav aria-label="Sekcje strony głównej" class="flex flex-wrap gap-2">
            <Button as-child :variant="isTiles ? 'default' : 'outline'"
                ><Link
                    :href="HomepageContentController.index('kafelki')"
                    :aria-current="isTiles ? 'page' : undefined"
                    ><LayoutGrid class="size-4" aria-hidden="true" />Kafelki
                    oferty</Link
                ></Button
            ><Button as-child :variant="isTiles ? 'outline' : 'default'"
                ><Link
                    :href="HomepageContentController.index('karuzela')"
                    :aria-current="!isTiles ? 'page' : undefined"
                    ><Images class="size-4" aria-hidden="true" />Karuzela</Link
                ></Button
            >
        </nav>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="
                    HomepageContentController.index(section, {
                        query: { page: items.current_page },
                    })
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <EmptyState
            v-if="items.total === 0"
            :title="
                isTiles
                    ? 'Nie ma jeszcze kafelków oferty'
                    : 'Nie ma jeszcze slajdów karuzeli'
            "
            description="Dodaj pierwszy element. Nowe treści są domyślnie ukryte, dopóki nie włączysz ich widoczności."
        />
        <div v-else class="space-y-4" :aria-busy="form.processing">
            <p class="text-sm text-muted-foreground">
                Elementów: {{ items.total }}. Kolejność uwzględnia również
                ukryte elementy.
            </p>
            <article
                v-for="(item, index) in items.data"
                :key="item.id"
                class="grid min-w-0 gap-4 rounded-2xl border border-border bg-card p-4 sm:grid-cols-[128px_minmax(0,1fr)] sm:p-5"
            >
                <ContentImage
                    v-if="item.image"
                    :src="item.image.src"
                    :alt="item.image.alt"
                    class="aspect-video w-full rounded-xl object-cover sm:aspect-square"
                />
                <div
                    v-else
                    class="flex aspect-video items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground sm:aspect-square"
                >
                    Bez zdjęcia
                </div>
                <div class="min-w-0 space-y-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <p class="text-xs text-muted-foreground">
                            Pozycja {{ offset + index + 1 }}
                        </p>
                        <span
                            :class="[
                                'rounded-full px-3 py-1 text-xs font-semibold',
                                item.is_published
                                    ? 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-100'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                            >{{
                                item.is_published
                                    ? 'Widoczny na stronie'
                                    : 'Ukryty'
                            }}</span
                        >
                    </div>
                    <h2 class="font-heading text-xl break-words">
                        {{ item.title || `Slajd #${item.id}` }}
                    </h2>
                    <p
                        v-if="item.description"
                        class="line-clamp-3 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ item.description }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <ActionButton
                            variant="outline"
                            :aria-label="`Przesuń wyżej: ${item.title || 'slajd'}`"
                            :disabled="form.processing || offset + index === 0"
                            @click="move(item, 'up')"
                            ><ArrowUp
                                class="size-4"
                                aria-hidden="true"
                            />Wyżej</ActionButton
                        >
                        <ActionButton
                            variant="outline"
                            :aria-label="`Przesuń niżej: ${item.title || 'slajd'}`"
                            :disabled="
                                form.processing ||
                                offset + index === items.total - 1
                            "
                            @click="move(item, 'down')"
                            ><ArrowDown
                                class="size-4"
                                aria-hidden="true"
                            />Niżej</ActionButton
                        >
                        <Button as-child variant="outline"
                            ><Link
                                :href="
                                    HomepageContentController.edit({
                                        section,
                                        item: item.id,
                                    })
                                "
                                >Edytuj<span class="sr-only"
                                    >: {{ item.title || 'slajd' }}</span
                                ></Link
                            ></Button
                        >
                        <ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="visibility(item)"
                            >{{ item.is_published ? 'Ukryj' : 'Pokaż'
                            }}<span class="sr-only"
                                >: {{ item.title || 'slajd' }}</span
                            ></ActionButton
                        >
                        <ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="confirm(item)"
                            >Usuń<span class="sr-only"
                                >: {{ item.title || 'slajd' }}</span
                            ></ActionButton
                        >
                    </div>
                    <p
                        v-if="form.processing && activeId === item.id"
                        role="status"
                        class="text-sm text-muted-foreground"
                    >
                        Zapisywanie zmian…
                    </p>
                </div>
            </article>
            <Pagination :links="items.links" />
        </div>
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="isTiles ? 'Usunąć kafelek?' : 'Usunąć slajd?'"
            :description="`Element „${selected?.title || 'Slajd'}” i jego zdjęcie zostaną usunięte. Tej operacji nie można cofnąć.`"
            confirm-label="Usuń element"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
