<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Usuń konto"
            description="Potwierdź usunięcie konta swoim hasłem."
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Uwaga</p>
                <p class="text-sm">Tej operacji nie można cofnąć.</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <ActionButton
                        variant="destructive"
                        data-test="delete-user-button"
                        >Usuń konto</ActionButton
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >Czy chcesz usunąć swoje konto?</DialogTitle
                            >
                            <DialogDescription>
                                Wpisz aktualne hasło, aby potwierdzić usunięcie
                                konta.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">Hasło</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Hasło"
                                :aria-invalid="
                                    errors.password ? true : undefined
                                "
                                :aria-describedby="
                                    errors.password
                                        ? 'delete-password-error'
                                        : undefined
                                "
                            />
                            <InputError
                                id="delete-password-error"
                                :message="errors.password"
                            />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <ActionButton
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Anuluj
                                </ActionButton>
                            </DialogClose>

                            <ActionButton
                                type="submit"
                                variant="destructive"
                                :loading="processing"
                                loading-text="Usuwanie…"
                                data-test="confirm-delete-user-button"
                            >
                                Usuń konto
                            </ActionButton>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
