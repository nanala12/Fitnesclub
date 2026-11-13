<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { AuthenticatedPageProps } from '@/types';

const props = defineProps<{
    profile: {
        first_name: string | null;
        last_name: string | null;
        email: string;
        phone: string | null;
    };
    mustVerifyEmail: boolean;
    status?: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Ustawienia profilu', href: edit() }] },
});

const page = usePage<AuthenticatedPageProps>();
const user = computed(() => page.props.auth.user);
const values = () => ({
    first_name: props.profile.first_name ?? '',
    last_name: props.profile.last_name ?? '',
    email: props.profile.email,
    phone: props.profile.phone ?? '',
});
const form = useForm(values());
const verification = useForm({});
function save() {
    form.patch(ProfileController.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(values());
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Ustawienia profilu" />
    <h1 class="sr-only">Ustawienia profilu</h1>
    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profil"
            description="Zaktualizuj swoje dane kontaktowe."
        />
        <FeedbackMessage
            v-if="!profile.first_name || !profile.last_name"
            title="Uzupełnij imię i nazwisko"
        >
            Dotychczas zapisane dane: {{ user.name }}. Wpisz imię i nazwisko w
            osobnych polach.
        </FeedbackMessage>
        <FeedbackMessage
            v-if="status === 'profile-updated'"
            title="Dane profilu zostały zapisane."
            variant="success"
        />
        <FeedbackMessage
            v-if="status === 'profile-email-changed'"
            title="Potwierdź nowy adres e-mail"
        >
            Dane zostały zapisane. Zleciliśmy wysłanie linku potwierdzającego na
            nowy adres. Do czasu potwierdzenia dostęp do panelu jest
            ograniczony.
        </FeedbackMessage>
        <form class="space-y-6" @submit.prevent="save">
            <fieldset :disabled="form.processing" class="space-y-6">
                <FormField
                    id="first_name"
                    label="Imię"
                    v-model="form.first_name"
                    :error="form.errors.first_name"
                    required
                    maxlength="120"
                    autocomplete="given-name"
                />
                <FormField
                    id="last_name"
                    label="Nazwisko"
                    v-model="form.last_name"
                    :error="form.errors.last_name"
                    required
                    maxlength="120"
                    autocomplete="family-name"
                />
                <FormField
                    id="email"
                    label="Adres e-mail"
                    type="email"
                    v-model="form.email"
                    :error="form.errors.email"
                    required
                    maxlength="255"
                    autocomplete="email"
                    hint="Po zmianie adresu poprosimy o jego ponowne potwierdzenie."
                />
                <FormField
                    id="phone"
                    label="Telefon z kierunkowym kraju"
                    type="tel"
                    v-model="form.phone"
                    :error="form.errors.phone"
                    required
                    autocomplete="tel"
                    placeholder="+48 600 700 800"
                    hint="Podaj numer razem z kierunkowym kraju, np. +48."
                />
            </fieldset>
            <ActionButton
                type="submit"
                :loading="form.processing"
                loading-text="Zapisywanie…"
                data-test="update-profile-button"
                >Zapisz zmiany</ActionButton
            >
        </form>
        <div
            v-if="mustVerifyEmail && !user.email_verified_at"
            class="space-y-3"
        >
            <p class="text-sm text-muted-foreground">
                Twój adres e-mail nie jest jeszcze potwierdzony.
            </p>
            <ActionButton
                variant="outline"
                :loading="verification.processing"
                loading-text="Wysyłanie…"
                @click="verification.post(send.url(), { preserveScroll: true })"
                >Wyślij ponownie link potwierdzający</ActionButton
            >
            <FeedbackMessage
                v-if="status === 'verification-link-sent'"
                title="Wysłaliśmy nowy link potwierdzający na Twój adres e-mail."
                variant="success"
            />
        </div>
    </div>
    <DeleteUser />
</template>
