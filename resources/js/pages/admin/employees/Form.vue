<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Admin/EmployeeController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

type Employee = {
    id: number;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string;
    phone: string | null;
    role: 'employee' | 'admin';
    suspended: boolean;
    invitationPending: boolean;
};
const props = defineProps<{ employee: Employee | null }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pracownicy', href: EmployeeController.index() },
        ],
    },
});
const title = computed(() =>
    props.employee ? 'Edytuj konto zespołu' : 'Dodaj konto zespołu',
);
const values = () => ({
    first_name: props.employee?.first_name ?? '',
    last_name: props.employee?.last_name ?? '',
    email: props.employee?.email ?? '',
    phone: props.employee?.phone ?? '',
    role: props.employee?.role ?? 'employee',
});
const form = useForm(values());
const access = useForm({ suspended: false });
const invitation = useForm<{ invitation?: string }>({});
const confirmOpen = ref(false);
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(values());
            form.reset();
        },
    };
    if (props.employee)
        form.patch(EmployeeController.update.url(props.employee.id), options);
    else form.post(EmployeeController.store.url(), options);
}
function toggleAccess() {
    if (!props.employee) return;
    access.suspended = !props.employee.suspended;
    access.patch(EmployeeController.suspension.url(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}
</script>
<template>
    <Head :title="title" />
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <Link
            :href="EmployeeController.index()"
            class="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
            >Wróć do listy pracowników</Link
        >
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <FeedbackMessage v-if="!employee" title="Pracownik ustawi własne hasło"
            >Po zapisaniu konta zlecimy wysłanie bezpiecznego zaproszenia na
            podany adres e-mail.</FeedbackMessage
        >
        <FeedbackMessage
            v-if="employee && (!employee.first_name || !employee.last_name)"
            title="Uzupełnij osobno imię i nazwisko"
            >Dotychczas zapisane dane: {{ employee.name }}.</FeedbackMessage
        >
        <form @submit.prevent="save" class="space-y-6">
            <fieldset :disabled="form.processing" class="space-y-6">
                <FormField
                    id="first_name"
                    label="Imię"
                    v-model="form.first_name"
                    required
                    maxlength="120"
                    autocomplete="given-name"
                    :error="form.errors.first_name"
                />
                <FormField
                    id="last_name"
                    label="Nazwisko"
                    v-model="form.last_name"
                    required
                    maxlength="120"
                    autocomplete="family-name"
                    :error="form.errors.last_name"
                />
                <FormField
                    id="email"
                    label="Adres e-mail"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="email"
                    :error="form.errors.email"
                    hint="Zmiana adresu wymaga jego potwierdzenia przez właściciela konta."
                />
                <FormField
                    id="phone"
                    label="Telefon z kierunkowym kraju"
                    v-model="form.phone"
                    type="tel"
                    required
                    autocomplete="tel"
                    placeholder="+48 600 700 800"
                    :error="form.errors.phone"
                />
                <div class="grid gap-2">
                    <Label for="role">Rola</Label>
                    <select
                        id="role"
                        name="role"
                        v-model="form.role"
                        required
                        :aria-invalid="form.errors.role ? true : undefined"
                        :aria-describedby="
                            form.errors.role ? 'role-error' : 'role-hint'
                        "
                        class="min-h-11 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        <option value="employee">Pracownik</option>
                        <option value="admin">Administrator</option>
                    </select>
                    <p id="role-hint" class="text-sm text-muted-foreground">
                        Administrator może zarządzać zespołem i konfiguracją
                        klubu.
                    </p>
                    <InputError id="role-error" :message="form.errors.role" />
                </div>
            </fieldset>
            <ActionButton
                type="submit"
                :loading="form.processing"
                loading-text="Zapisywanie…"
                data-test="save-employee"
                class="h-auto w-full whitespace-normal sm:w-auto"
                >{{
                    employee
                        ? 'Zapisz zmiany'
                        : 'Utwórz konto i wyślij zaproszenie'
                }}</ActionButton
            >
        </form>
        <section
            v-if="employee"
            class="space-y-5 border-t border-border pt-6"
            aria-label="Dostęp do konta"
        >
            <h2 class="text-lg font-semibold">Dostęp do konta</h2>
            <FeedbackMessage
                :title="
                    employee.suspended ? 'Dostęp zawieszony' : 'Dostęp włączony'
                "
                :variant="employee.suspended ? 'warning' : 'info'"
            >
                {{
                    employee.suspended
                        ? 'To konto nie może korzystać z panelu. Przywrócenie dostępu nie przywróci wcześniejszych sesji.'
                        : 'Zawieszenie konta natychmiast blokuje kolejne operacje, także w istniejącej sesji.'
                }}
            </FeedbackMessage>
            <ConfirmDialog
                v-model:open="confirmOpen"
                :title="
                    employee.suspended
                        ? 'Przywrócić dostęp?'
                        : 'Zawiesić dostęp?'
                "
                :description="
                    employee.suspended
                        ? 'Właściciel konta będzie mógł ponownie się zalogować.'
                        : 'Właściciel konta zostanie wylogowany i utraci dostęp do panelu.'
                "
                :confirm-label="
                    employee.suspended ? 'Przywróć dostęp' : 'Zawieś dostęp'
                "
                :destructive="!employee.suspended"
                :processing="access.processing"
                :error="Object.values(access.errors)[0]"
                @confirm="toggleAccess"
            >
                <template #trigger
                    ><ActionButton
                        variant="outline"
                        data-test="toggle-employee-access"
                        >{{
                            employee.suspended
                                ? 'Przywróć dostęp'
                                : 'Zawieś dostęp'
                        }}</ActionButton
                    ></template
                >
            </ConfirmDialog>
            <div v-if="employee.invitationPending" class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    Konto oczekuje na ustawienie hasła. Jeśli link wygasł lub
                    wiadomość nie dotarła, możesz zlecić ponowną wysyłkę.
                </p>
                <ActionButton
                    variant="outline"
                    :disabled="employee.suspended"
                    :loading="invitation.processing"
                    loading-text="Zlecanie wysyłki…"
                    @click="
                        invitation.post(
                            EmployeeController.invitation.url(employee.id),
                            { preserveScroll: true },
                        )
                    "
                    >Wyślij ponownie zaproszenie</ActionButton
                >
                <InputError :message="invitation.errors.invitation" />
            </div>
        </section>
    </div>
</template>
