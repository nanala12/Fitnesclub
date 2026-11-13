<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    status?: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Bezpieczeństwo konta',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Bezpieczeństwo konta" />

    <h1 class="sr-only">Bezpieczeństwo konta</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Zmień hasło"
            description="Podaj obecne hasło i ustaw nowe, aby chronić swoje konto."
        />

        <FeedbackMessage
            v-if="props.status === 'password-updated'"
            title="Hasło zostało zmienione."
            variant="success"
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Obecne hasło</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    required
                    :aria-invalid="errors.current_password ? true : undefined"
                    :aria-describedby="
                        errors.current_password
                            ? 'current_password-error'
                            : undefined
                    "
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Obecne hasło"
                />
                <InputError
                    id="current_password-error"
                    :message="errors.current_password"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nowe hasło</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :aria-invalid="errors.password ? true : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Nowe hasło"
                    :passwordrules="props.passwordRules"
                />
                <InputError id="password-error" :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Powtórz nowe hasło</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    :aria-invalid="
                        errors.password_confirmation ? true : undefined
                    "
                    :aria-describedby="
                        errors.password_confirmation
                            ? 'password_confirmation-error'
                            : undefined
                    "
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Powtórz nowe hasło"
                    :passwordrules="props.passwordRules"
                />
                <InputError
                    id="password_confirmation-error"
                    :message="errors.password_confirmation"
                />
            </div>

            <div class="flex items-center gap-4">
                <ActionButton
                    type="submit"
                    :loading="processing"
                    loading-text="Zapisywanie…"
                    data-test="update-password-button"
                >
                    Zapisz hasło
                </ActionButton>
            </div>
        </Form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :canManagePasskeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
