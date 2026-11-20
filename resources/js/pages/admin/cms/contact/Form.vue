<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ContactSettingsController from '@/actions/App/Http/Controllers/Admin/Cms/ContactSettingsController';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import TimeField from '@/components/shared/TimeField.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { contactFields } from '@/types/club';
import type { ClubContact, OpeningDay } from '@/types/club';
const props = defineProps<{
    contact: ClubContact;
    hours: OpeningDay[];
    revision: number;
}>();
const form = useForm({
    club_name: props.contact.club_name ?? '',
    contact_email: props.contact.contact_email ?? '',
    contact_phone: props.contact.contact_phone ?? '',
    street_address: props.contact.street_address ?? '',
    postal_code: props.contact.postal_code ?? '',
    city: props.contact.city ?? '',
    map_url: props.contact.map_url ?? '',
    revision: props.revision,
    hours: props.hours.map(({ weekday, status, opens_at, closes_at }) => ({
        weekday,
        status,
        opens_at,
        closes_at,
    })),
});
function error(path: string) {
    return (form.errors as Record<string, string>)[path];
}
function save() {
    form.patch(ContactSettingsController.update.url(), {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="Edytuj kontakt i godziny" />
    <div class="mx-auto max-w-3xl space-y-6">
        <Link
            :href="ContactSettingsController.index()"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Kontakt i godziny</Link
        >
        <h1 class="font-heading text-3xl">Edytuj kontakt i godziny</h1>
        <FeedbackMessage
            v-if="form.errors.revision"
            variant="error"
            :title="form.errors.revision"
            ><Link :href="ContactSettingsController.edit()" class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <form class="space-y-6" @submit.prevent="save">
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Dane kontaktowe</legend>
                <h2 class="font-heading text-2xl">Dane kontaktowe</h2>
                <p class="text-sm text-muted-foreground">
                    Wpisz aktualne dane klubu. Puste pola nie dodają
                    przykładowych informacji na stronie.
                </p>
                <FormField
                    v-for="field in contactFields"
                    :id="field.key"
                    :key="field.key"
                    v-model="form[field.key]"
                    :label="field.label"
                    :type="field.type"
                    :maxlength="field.max"
                    :error="form.errors[field.key]"
                    :hint="
                        field.key === 'contact_phone'
                            ? 'Numer z prefiksem kraju, np. +48. Spacje i myślniki zostaną usunięte.'
                            : field.key === 'map_url'
                              ? 'Wklej odnośnik HTTPS do lokalizacji klubu, bez kodu osadzanej mapy.'
                              : undefined
                    "
                />
            </fieldset>
            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-5 rounded-2xl border border-border bg-card p-4 sm:p-6"
            >
                <legend class="sr-only">Godziny otwarcia</legend>
                <h2 class="font-heading text-2xl">Godziny otwarcia</h2>
                <p class="text-sm text-muted-foreground">
                    Jeden przedział w danym dniu, według czasu polskiego. „Brak
                    informacji” pozostawia godziny nieuzupełnione.
                </p>
                <InputError :message="form.errors.hours" />
                <div
                    v-for="(day, index) in form.hours"
                    :key="day.weekday"
                    class="grid min-w-0 gap-3 border-b border-border pb-5"
                >
                    <h3 class="font-semibold">{{ hours[index].label }}</h3>
                    <Label :for="`hours-${day.weekday}-status`"
                        >Dostępność w tym dniu</Label
                    ><select
                        :id="`hours-${day.weekday}-status`"
                        v-model="day.status"
                        :aria-invalid="!!error(`hours.${index}.status`)"
                        :aria-describedby="`hours-${day.weekday}-error`"
                        class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm"
                    >
                        <option value="unknown">Brak informacji</option>
                        <option value="open">Otwarte</option>
                        <option value="closed">Nieczynne</option></select
                    ><InputError
                        :id="`hours-${day.weekday}-error`"
                        :message="
                            error(`hours.${index}.status`) ||
                            error(`hours.${index}.weekday`)
                        "
                    />
                    <div
                        v-if="day.status === 'open'"
                        class="grid min-w-0 gap-4 sm:grid-cols-2"
                    >
                        <TimeField
                            :id="`hours-${day.weekday}-opens`"
                            v-model="day.opens_at"
                            label="Otwarcie"
                            required
                            :error="error(`hours.${index}.opens_at`)"
                        /><TimeField
                            :id="`hours-${day.weekday}-closes`"
                            v-model="day.closes_at"
                            label="Zamknięcie"
                            required
                            :error="error(`hours.${index}.closes_at`)"
                        />
                    </div>
                </div>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="form.processing"
                    loading-text="Zapisywanie danych…"
                    >Zapisz zmiany</ActionButton
                ><Link
                    :href="ContactSettingsController.index()"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Anuluj</Link
                >
            </div>
        </form>
    </div>
</template>
