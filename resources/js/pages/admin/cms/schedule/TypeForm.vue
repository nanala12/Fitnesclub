<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ClassScheduleController from '@/actions/App/Http/Controllers/Admin/Cms/ClassScheduleController';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { ClassTypeItem } from '@/types/club';
const props = defineProps<{ type: ClassTypeItem | null; revision: number }>();
const form = useForm({
    name: props.type?.name ?? '',
    description: props.type?.description ?? '',
    is_published: props.type?.is_published ?? false,
    revision: props.revision,
});
function save() {
    if (props.type)
        form.patch(ClassScheduleController.typeUpdate.url(props.type.id));
    else form.post(ClassScheduleController.typeStore.url());
}
</script>
<template>
    <Head :title="type ? 'Edytuj rodzaj zajęć' : 'Dodaj rodzaj zajęć'" />
    <div class="mx-auto max-w-2xl space-y-6">
        <Link
            :href="ClassScheduleController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Grafik zajęć</Link
        >
        <h1 class="font-heading text-3xl">
            {{ type ? 'Edytuj rodzaj zajęć' : 'Dodaj rodzaj zajęć' }}
        </h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link
                :href="
                    type
                        ? ClassScheduleController.typeEdit(type.id)
                        : ClassScheduleController.typeCreate()
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
                <legend class="sr-only">Rodzaj zajęć</legend>
                <FormField
                    id="name"
                    v-model="form.name"
                    label="Nazwa zajęć"
                    maxlength="150"
                    required
                    :error="form.errors.name"
                />
                <div class="grid gap-2">
                    <Label for="description">Opis (opcjonalnie)</Label
                    ><textarea
                        id="description"
                        v-model="form.description"
                        rows="5"
                        maxlength="2000"
                        :aria-invalid="!!form.errors.description"
                        aria-describedby="description-error"
                        class="w-full min-w-0 rounded-lg border border-input bg-card p-3 text-sm"
                    /><InputError
                        id="description-error"
                        :message="form.errors.description"
                    />
                </div>
                <label
                    for="is_published"
                    class="flex min-h-11 items-center gap-3 text-sm font-semibold"
                    ><input
                        id="is_published"
                        v-model="form.is_published"
                        type="checkbox"
                        class="size-5 accent-primary"
                    />Pokaż ten rodzaj zajęć na stronie</label
                ><InputError :message="form.errors.is_published" />
                <p class="text-sm text-muted-foreground">
                    Ukrycie rodzaju zajęć ukrywa wszystkie jego terminy na
                    stronie. Nowy rodzaj jest domyślnie ukryty.
                </p>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    loading-text="Zapisywanie zajęć…"
                    >{{
                        type ? 'Zapisz zmiany' : 'Dodaj rodzaj zajęć'
                    }}</ActionButton
                ><Link
                    :href="ClassScheduleController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
