<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import FormField from '@/components/shared/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Załóż konto',
        description: 'Podaj swoje dane, aby założyć konto.',
    },
});
</script>

<template>
    <Head title="Rejestracja" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Imię i nazwisko</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    autocomplete="name"
                    name="name"
                    placeholder="Imię i nazwisko"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Adres e-mail</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <FormField
                id="phone"
                label="Telefon z kierunkowym kraju"
                type="tel"
                name="phone"
                autocomplete="tel"
                placeholder="+48 600 700 800"
                hint="Podaj numer razem z kierunkowym kraju, np. +48."
                :error="errors.phone"
                required
            />

            <div class="grid gap-2">
                <Label for="password">Hasło</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    placeholder="Hasło"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Powtórz hasło</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Powtórz hasło"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Załóż konto
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Masz już konto?
            <TextLink :href="login()" class="underline underline-offset-4"
                >Zaloguj się</TextLink
            >
        </div>
    </Form>
</template>
