<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FormField from '@/components/shared/FormField.vue';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { AuthenticatedPageProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Ustawienia profilu',
                href: edit(),
            },
        ],
    },
});

const page = usePage<AuthenticatedPageProps>();
const user = computed(() => page.props.auth.user);
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

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <FormField
                id="name"
                label="Imię i nazwisko"
                :default-value="user.name"
                :error="errors.name"
                required
                autocomplete="name"
            />
            <FormField
                id="email"
                label="Adres e-mail"
                type="email"
                :default-value="user.email"
                :error="errors.email"
                required
                autocomplete="email"
            />

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Twój adres e-mail nie jest jeszcze potwierdzony.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Wyślij ponownie wiadomość z linkiem potwierdzającym.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    Wysłaliśmy nowy link potwierdzający na Twój adres e-mail.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="processing"
                    loading-text="Zapisywanie…"
                    data-test="update-profile-button"
                    >Zapisz zmiany</ActionButton
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
