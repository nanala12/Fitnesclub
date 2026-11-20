<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ClassScheduleController from '@/actions/App/Http/Controllers/Admin/Cms/ClassScheduleController';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import TimeField from '@/components/shared/TimeField.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { ClassSlot, ClassTypeItem } from '@/types/club';
const props = defineProps<{
    slot: ClassSlot | null;
    revision: number;
    typeOptions: Pick<ClassTypeItem, 'id' | 'name' | 'is_published'>[];
    weekdays: { value: number; label: string }[];
}>();
const form = useForm({
    class_type_id: props.slot ? String(props.slot.class_type_id) : '',
    weekday: props.slot ? String(props.slot.weekday) : '',
    starts_at: props.slot?.starts_at ?? '',
    ends_at: props.slot?.ends_at ?? '',
    instructor_name: props.slot?.instructor_name ?? '',
    is_published: props.slot?.is_published ?? false,
    revision: props.revision,
});
function save() {
    if (props.slot)
        form.patch(ClassScheduleController.slotUpdate.url(props.slot.id));
    else form.post(ClassScheduleController.slotStore.url());
}
</script>
<template>
    <Head :title="slot ? 'Edytuj termin zajęć' : 'Dodaj termin zajęć'" />
    <div class="mx-auto max-w-2xl space-y-6">
        <Link
            :href="ClassScheduleController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Grafik zajęć</Link
        >
        <h1 class="font-heading text-3xl">
            {{ slot ? 'Edytuj termin zajęć' : 'Dodaj termin zajęć' }}
        </h1>
        <FeedbackMessage
            v-if="!typeOptions.length"
            title="Najpierw dodaj rodzaj zajęć"
            ><Link
                :href="ClassScheduleController.typeCreate()"
                class="underline"
                >Dodaj rodzaj zajęć</Link
            ></FeedbackMessage
        >
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                :href="
                    slot
                        ? ClassScheduleController.slotEdit(slot.id)
                        : ClassScheduleController.slotCreate()
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-5" @submit.prevent="save">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Termin zajęć</legend>
                <div class="grid min-w-0 gap-2">
                    <Label for="class_type_id">Rodzaj zajęć</Label
                    ><select
                        id="class_type_id"
                        v-model="form.class_type_id"
                        required
                        :aria-invalid="!!form.errors.class_type_id"
                        aria-describedby="class-type-error"
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm"
                    >
                        <option value="" disabled>Wybierz rodzaj zajęć</option>
                        <option
                            v-for="type in typeOptions"
                            :key="type.id"
                            :value="String(type.id)"
                        >
                            {{ type.name
                            }}{{ type.is_published ? '' : ' (ukryty)' }}
                        </option></select
                    ><InputError
                        id="class-type-error"
                        :message="form.errors.class_type_id"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="weekday">Dzień tygodnia</Label
                    ><select
                        id="weekday"
                        v-model="form.weekday"
                        required
                        :aria-invalid="!!form.errors.weekday"
                        aria-describedby="weekday-error"
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm"
                    >
                        <option value="" disabled>Wybierz dzień</option>
                        <option
                            v-for="day in weekdays"
                            :key="day.value"
                            :value="String(day.value)"
                        >
                            {{ day.label }}
                        </option></select
                    ><InputError
                        id="weekday-error"
                        :message="form.errors.weekday"
                    />
                </div>
                <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                    <TimeField
                        id="starts_at"
                        v-model="form.starts_at"
                        label="Początek"
                        required
                        :error="form.errors.starts_at"
                    /><TimeField
                        id="ends_at"
                        v-model="form.ends_at"
                        label="Koniec"
                        required
                        :error="form.errors.ends_at"
                    />
                </div>
                <p class="text-sm text-muted-foreground">
                    Tygodniowy termin według czasu polskiego.
                </p>
                <FormField
                    id="instructor_name"
                    v-model="form.instructor_name"
                    label="Prowadzący (opcjonalnie)"
                    maxlength="150"
                    :error="form.errors.instructor_name"
                />
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 accent-primary"
                    />Pokaż termin na stronie</label
                ><InputError :message="form.errors.is_published" />
                <p class="text-sm text-muted-foreground">
                    Termin będzie widoczny, gdy publikacja jest włączona również
                    dla rodzaju zajęć. Nowy termin jest domyślnie ukryty.
                </p>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    :disabled="!typeOptions.length"
                    loading-text="Zapisywanie terminu…"
                    >{{ slot ? 'Zapisz zmiany' : 'Dodaj termin' }}</ActionButton
                ><Link
                    :href="ClassScheduleController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
