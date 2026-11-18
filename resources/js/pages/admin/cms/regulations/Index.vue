<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RegulationController from '@/actions/App/Http/Controllers/Admin/Cms/RegulationController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import type { RegulationItem } from '@/types/regulations';
import type { PaginationLink } from '@/types';
const props = defineProps<{
    versions: {
        data: RegulationItem[];
        total: number;
        current_page: number;
        links: PaginationLink[];
    };
    revision: number;
}>();
const form = useForm({
    revision: props.revision,
    page: props.versions.current_page,
});
const selected = ref<RegulationItem | null>(null);
const operation = ref<'publish' | 'delete'>('publish');
const open = ref(false);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
const labels = {
    draft: 'Szkic',
    current: 'Aktualny regulamin',
    history: 'Opublikowana wersja',
};
function confirm(version: RegulationItem, action: 'publish' | 'delete') {
    selected.value = version;
    operation.value = action;
    form.clearErrors();
    open.value = true;
}
function execute() {
    if (!selected.value) return;
    form.revision = props.revision;
    form.page = props.versions.current_page;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };
    if (operation.value === 'publish')
        form.post(RegulationController.publish.url(selected.value.id), options);
    else
        form.delete(
            RegulationController.destroy.url(selected.value.id),
            options,
        );
}
function date(value: string) {
    return new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Europe/Warsaw',
    }).format(new Date(value));
}
</script>
<template>
    <Head title="Regulamin" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl sm:text-4xl">Regulamin</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Przygotuj szkic i opublikuj nową wersję. Opublikowane treści
                    pozostają w historii.
                </p>
            </div>
            <Button as-child
                ><Link :href="RegulationController.create()"
                    >Utwórz nową wersję</Link
                ></Button
            >
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="RegulationController.index()"
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <EmptyState
            v-if="!versions.total"
            title="Nie ma jeszcze regulaminu"
            description="Utwórz pierwszy szkic, sprawdź jego treść, a następnie opublikuj go na stronie."
        />
        <div v-else class="space-y-4" :aria-busy="form.processing">
            <article
                v-for="version in versions.data"
                :key="version.id"
                class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-4 sm:p-6"
                :data-regulation-id="version.id"
            >
                <span class="rounded-full bg-muted px-3 py-1 text-xs">{{
                    labels[version.status]
                }}</span>
                <h2 class="font-heading text-2xl">
                    Wersja {{ version.versionNumber }}
                </h2>
                <p
                    v-if="version.publishedAt"
                    class="text-sm text-muted-foreground"
                >
                    Publikacja:
                    <time :datetime="version.publishedAt">{{
                        date(version.publishedAt)
                    }}</time>
                </p>
                <p
                    v-if="version.status === 'draft' && !version.canPublish"
                    class="text-sm text-muted-foreground"
                >
                    Opublikowano już nowszy regulamin. Utwórz nową wersję na
                    podstawie aktualnej treści.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline"
                        ><Link :href="RegulationController.edit(version.id)">{{
                            version.status === 'draft'
                                ? 'Edytuj szkic'
                                : 'Zobacz wersję'
                        }}</Link></Button
                    >
                    <ActionButton
                        v-if="version.canPublish"
                        :disabled="form.processing"
                        @click="confirm(version, 'publish')"
                        >Opublikuj</ActionButton
                    >
                    <Button
                        v-if="version.status === 'current'"
                        as-child
                        variant="outline"
                        ><Link href="/regulamin"
                            >Zobacz na stronie</Link
                        ></Button
                    >
                    <ActionButton
                        v-if="version.status === 'draft'"
                        variant="outline"
                        :disabled="form.processing"
                        @click="confirm(version, 'delete')"
                        >Usuń szkic</ActionButton
                    >
                </div>
            </article>
            <Pagination :links="versions.links" />
        </div>
        <ConfirmDialog
            v-model:open="open"
            :title="
                operation === 'publish'
                    ? 'Opublikować regulamin?'
                    : 'Usunąć szkic?'
            "
            :description="
                operation === 'publish'
                    ? `Wersja ${selected?.versionNumber ?? ''} zastąpi aktualny regulamin na stronie. Po publikacji jej treści i zdjęcia nie będzie można zmienić ani usunąć.`
                    : 'Szkic i jego zdjęcie zostaną usunięte. Tej operacji nie można cofnąć.'
            "
            :confirm-label="
                operation === 'publish' ? 'Opublikuj regulamin' : 'Usuń szkic'
            "
            :destructive="operation === 'delete'"
            :processing="form.processing"
            :error="feedback"
            @confirm="execute"
        />
    </div>
</template>
