<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ClassScheduleController from '@/actions/App/Http/Controllers/Admin/Cms/ClassScheduleController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import type { ClassSlotItem, ClassTypeItem } from '@/types/club';
import type { PaginationLink } from '@/types';
type Page<T> = {
    data: T[];
    total: number;
    current_page: number;
    links: PaginationLink[];
};
const props = defineProps<{
    types: Page<ClassTypeItem>;
    slots: Page<ClassSlotItem>;
    revision: number;
    isScheduleVisible: boolean;
}>();
const form = useForm({
    revision: props.revision,
    page: props.slots.current_page,
    types_page: props.types.current_page,
    is_published: false,
    is_schedule_visible: props.isScheduleVisible,
});
const open = ref(false);
const selected = ref<{ id: number; type: boolean; name: string } | null>(null);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
function prepare() {
    form.clearErrors();
    form.revision = props.revision;
    form.page = props.slots.current_page;
    form.types_page = props.types.current_page;
}
function toggleSchedule() {
    prepare();
    form.is_schedule_visible = !props.isScheduleVisible;
    form.patch(ClassScheduleController.visibility.url(), {
        preserveScroll: true,
    });
}
function visibility(type: boolean, item: ClassTypeItem | ClassSlotItem) {
    prepare();
    form.is_published = !item.is_published;
    form.patch(
        type
            ? ClassScheduleController.typeVisibility.url(item.id)
            : ClassScheduleController.slotVisibility.url(item.id),
        { preserveScroll: true },
    );
}
function confirm(type: boolean, id: number, name: string) {
    selected.value = { type, id, name };
    form.clearErrors();
    open.value = true;
}
function remove() {
    if (!selected.value) return;
    prepare();
    form.delete(
        selected.value.type
            ? ClassScheduleController.typeDestroy.url(selected.value.id)
            : ClassScheduleController.slotDestroy.url(selected.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>
<template>
    <Head title="Grafik zajęć" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl">Grafik zajęć</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Zarządzaj rodzajami zajęć i ich tygodniowymi terminami.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="ClassScheduleController.typeCreate()"
                        >Dodaj rodzaj zajęć</Link
                    ></Button
                ><Button as-child
                    ><Link :href="ClassScheduleController.slotCreate()"
                        >Dodaj termin</Link
                    ></Button
                >
            </div>
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="ClassScheduleController.index()"
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <section
            class="space-y-3 rounded-2xl border border-border bg-card p-4 sm:p-6"
            aria-labelledby="schedule-visibility-heading"
        >
            <h2 id="schedule-visibility-heading" class="font-heading text-xl">
                Widoczność grafiku na stronie
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    isScheduleVisible
                        ? 'Publiczny grafik i przycisk „Grafik” w menu są widoczne.'
                        : 'Publiczny grafik i przycisk „Grafik” w menu są ukryte. Zajęcia możesz nadal edytować w panelu.'
                }}
            </p>
            <ActionButton
                variant="outline"
                :disabled="form.processing"
                :aria-pressed="isScheduleVisible"
                @click="toggleSchedule"
                >{{
                    isScheduleVisible
                        ? 'Ukryj publiczny grafik'
                        : 'Pokaż publiczny grafik'
                }}</ActionButton
            >
        </section>
        <section
            aria-labelledby="types-heading"
            class="space-y-4"
            :aria-busy="form.processing"
        >
            <h2 id="types-heading" class="font-heading text-2xl">
                Rodzaje zajęć
            </h2>
            <EmptyState
                v-if="!types.total"
                title="Nie ma jeszcze rodzajów zajęć"
                description="Dodaj nazwę i opis zajęć, a potem ustal ich terminy."
            />
            <div v-else class="grid min-w-0 gap-4 lg:grid-cols-2">
                <article
                    v-for="type in types.data"
                    :key="type.id"
                    :data-class-type="type.id"
                    class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-4"
                >
                    <h3 class="font-heading text-xl break-words">
                        {{ type.name }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ type.is_published ? 'Widoczny' : 'Ukryty' }} ·
                        Terminów: {{ type.slotsCount }}
                    </p>
                    <p
                        v-if="type.description"
                        class="line-clamp-3 text-sm break-words text-muted-foreground"
                    >
                        {{ type.description }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline"
                            ><Link
                                :href="
                                    ClassScheduleController.typeEdit(type.id)
                                "
                                >Edytuj<span class="sr-only">
                                    rodzaj {{ type.name }}</span
                                ></Link
                            ></Button
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="visibility(true, type)"
                            >{{ type.is_published ? 'Ukryj' : 'Pokaż'
                            }}<span class="sr-only">
                                rodzaj {{ type.name }}</span
                            ></ActionButton
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="confirm(true, type.id, type.name)"
                            >Usuń<span class="sr-only">
                                rodzaj {{ type.name }}</span
                            ></ActionButton
                        >
                    </div>
                </article>
            </div>
            <Pagination
                v-if="types.total"
                :links="types.links"
                aria-label="Strony rodzajów zajęć"
            />
        </section>
        <section
            aria-labelledby="slots-heading"
            class="space-y-4"
            :aria-busy="form.processing"
        >
            <h2 id="slots-heading" class="font-heading text-2xl">
                Terminy zajęć
            </h2>
            <EmptyState
                v-if="!slots.total"
                title="Nie ma jeszcze terminów zajęć"
                description="Dodaj termin i włącz jego widoczność, aby pojawił się w grafiku."
            />
            <div v-else class="grid min-w-0 gap-4 lg:grid-cols-2">
                <article
                    v-for="slot in slots.data"
                    :key="slot.id"
                    :data-class-slot="slot.id"
                    class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-4"
                >
                    <p class="text-sm font-semibold">
                        {{ slot.dayLabel }} · {{ slot.starts_at }}–{{
                            slot.ends_at
                        }}
                    </p>
                    <h3 class="font-heading text-xl break-words">
                        {{ slot.typeName }}
                    </h3>
                    <p v-if="slot.instructor_name" class="text-sm break-words">
                        Prowadzący: {{ slot.instructor_name }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            slot.is_published
                                ? 'Widoczność terminu włączona'
                                : 'Termin ukryty'
                        }}
                    </p>
                    <p
                        v-if="!slot.typePublished"
                        class="text-sm text-muted-foreground"
                    >
                        Rodzaj zajęć jest ukryty. Włącz również jego widoczność,
                        aby pokazać termin na stronie.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline"
                            ><Link
                                :href="
                                    ClassScheduleController.slotEdit(slot.id)
                                "
                                >Edytuj<span class="sr-only">
                                    termin {{ slot.typeName }},
                                    {{ slot.dayLabel }},
                                    {{ slot.starts_at }}</span
                                ></Link
                            ></Button
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="visibility(false, slot)"
                            >{{ slot.is_published ? 'Ukryj' : 'Pokaż'
                            }}<span class="sr-only">
                                termin {{ slot.typeName }}, {{ slot.dayLabel }},
                                {{ slot.starts_at }}</span
                            ></ActionButton
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="
                                confirm(
                                    false,
                                    slot.id,
                                    `${slot.typeName}, ${slot.dayLabel}, ${slot.starts_at}`,
                                )
                            "
                            >Usuń<span class="sr-only">
                                termin {{ slot.typeName }}, {{ slot.dayLabel }},
                                {{ slot.starts_at }}</span
                            ></ActionButton
                        >
                    </div>
                </article>
            </div>
            <Pagination
                v-if="slots.total"
                :links="slots.links"
                aria-label="Strony terminów zajęć"
            />
        </section>
        <Link
            v-if="isScheduleVisible"
            href="/grafik"
            class="inline-flex min-h-11 items-center underline"
            >Zobacz grafik na stronie klubu</Link
        >
        <ConfirmDialog
            v-model:open="open"
            :title="
                selected?.type ? 'Usunąć rodzaj zajęć?' : 'Usunąć termin zajęć?'
            "
            :description="
                selected?.type
                    ? `Rodzaj „${selected.name}” można usunąć po przeniesieniu lub usunięciu wszystkich jego terminów.`
                    : `Termin „${selected?.name ?? ''}” zostanie usunięty. Tej operacji nie można cofnąć.`
            "
            confirm-label="Usuń"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
