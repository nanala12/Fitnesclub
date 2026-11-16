<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
    isAdminInvitation: boolean;
}>();
const minimumLength = computed(
    () =>
        Number(props.passwordRules.match(/minlength:\s*(\d+)/)?.[1]) ||
        undefined,
);
const maximumLength = computed(
    () =>
        Number(props.passwordRules.match(/maxlength:\s*(\d+)/)?.[1]) ||
        undefined,
);
const requirements = computed(() =>
    [
        minimumLength.value ? `Co najmniej ${minimumLength.value} znaków.` : '',
        props.passwordRules.includes('required: upper')
            ? 'Użyj małej i wielkiej litery.'
            : props.passwordRules.includes('required: lower')
              ? 'Użyj litery.'
              : '',
        props.passwordRules.includes('required: digit') ? 'Dodaj cyfrę.' : '',
        props.passwordRules.includes('required: special')
            ? 'Dodaj znak specjalny.'
            : '',
        'Unikaj haseł używanych w innych serwisach.',
    ]
        .filter(Boolean)
        .join(' '),
);
</script>

<template>
    <Head title="Ustaw hasło" />
    <section
        aria-labelledby="page-title"
        class="w-full max-w-[530px] rounded-[18px] border border-border bg-card px-[22px] py-[27px] shadow-[0_12px_40px_rgba(70,58,47,0.055)] min-[601px]:px-[42px] min-[601px]:py-[38px]"
    >
        <p
            class="text-xs font-extrabold tracking-[0.13em] text-[#88776a] uppercase dark:text-primary"
        >
            Aktywacja konta
        </p>
        <h1
            id="page-title"
            class="mt-[11px] mb-3 text-[clamp(28px,4vw,36px)] leading-[1.16] font-bold tracking-[-1px]"
        >
            Ustaw swoje hasło
        </h1>
        <p class="mb-[27px] text-[15px] leading-[1.55] text-muted-foreground">
            {{
                isAdminInvitation
                    ? 'Twoje konto administratora jest już przygotowane.'
                    : 'Twoje konto pracownicze jest już przygotowane.'
            }}
            Utwórz hasło, aby bezpiecznie zalogować się do panelu ActiveFit.
        </p>
        <p class="mb-[25px] rounded-[10px] bg-secondary px-[15px] py-3 text-sm">
            Twoim loginem jest adres e-mail, na który wysłaliśmy link do zmiany
            hasła.
        </p>
        <Form
            v-bind="update.form()"
            :transform="(data) => ({ ...data, token, email })"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="space-y-[19px]"
        >
            <div
                v-if="errors.email || errors.token"
                role="alert"
                class="rounded-[10px] border border-destructive/30 bg-destructive/10 px-[15px] py-3 text-[13px] text-destructive"
            >
                {{ errors.email || errors.token }}
            </div>
            <div class="space-y-2">
                <Label for="password" class="text-[13px] font-bold"
                    >Nowe hasło</Label
                >
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :minlength="minimumLength"
                    :maxlength="maximumLength"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    toggle-text
                    toggle-description="nowe hasło"
                    :disabled="processing"
                    :aria-invalid="errors.password ? true : undefined"
                    :aria-describedby="
                        errors.password
                            ? 'password-help password-error'
                            : 'password-help'
                    "
                    class="h-[49px] rounded-[9px] bg-white text-[15px] md:text-[15px] dark:bg-background"
                />
                <p
                    id="password-help"
                    class="text-xs leading-[1.55] text-muted-foreground"
                >
                    {{ requirements }}
                </p>
                <InputError id="password-error" :message="errors.password" />
            </div>
            <div class="space-y-2">
                <Label for="password_confirmation" class="text-[13px] font-bold"
                    >Powtórz nowe hasło</Label
                >
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    :minlength="minimumLength"
                    :maxlength="maximumLength"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    toggle-text
                    toggle-description="potwierdzenie hasła"
                    :disabled="processing"
                    :aria-invalid="errors.password ? true : undefined"
                    :aria-describedby="
                        errors.password ? 'password-error' : undefined
                    "
                    class="h-[49px] rounded-[9px] bg-white text-[15px] md:text-[15px] dark:bg-background"
                />
            </div>
            <ActionButton
                type="submit"
                :loading="processing"
                loading-text="Zapisywanie…"
                class="mt-1 min-h-[49px] w-full rounded-[9px] px-3 text-[15px] leading-snug font-bold whitespace-normal"
                data-test="activate-account-button"
                ><span
                    >Zapisz hasło i aktywuj konto
                    <span aria-hidden="true">→</span></span
                ></ActionButton
            >
        </Form>
        <p class="mt-5 text-center text-[13px] text-muted-foreground">
            Link nie działa?
            <a
                href="mailto:magda@activefit.pl?subject=Nowy%20link%20do%20ustawienia%20has%C5%82a"
                class="inline-flex min-h-11 items-center font-bold text-primary underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >Skontaktuj się z klubem</a
            >
        </p>
    </section>
</template>
