<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmployeeController from '@/actions/App/Http/Controllers/Admin/EmployeeController';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';

const props = defineProps<{
    employee: { id: number; name: string; suspended: boolean };
    page: number;
}>();
const open = ref(false);
const form = useForm({ suspended: false, return_to: 'list', page: props.page });
function toggleAccess() {
    form.suspended = !props.employee.suspended;
    form.page = props.page;
    form.patch(EmployeeController.suspension.url(props.employee.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        :title="employee.suspended ? 'Przywrócić dostęp?' : 'Zawiesić dostęp?'"
        :description="`${employee.name}: ${employee.suspended ? 'właściciel konta będzie mógł ponownie się zalogować.' : 'konto zostanie wylogowane i utraci dostęp do panelu.'}`"
        :confirm-label="
            employee.suspended ? 'Przywróć dostęp' : 'Zawieś dostęp'
        "
        :destructive="!employee.suspended"
        :processing="form.processing"
        :error="Object.values(form.errors)[0]"
        @confirm="toggleAccess"
    >
        <template #trigger>
            <ActionButton
                :variant="employee.suspended ? 'outline' : 'destructive'"
                :disabled="form.processing"
                :aria-label="`${employee.suspended ? 'Przywróć dostęp' : 'Zawieś dostęp'}: ${employee.name}`"
                data-test="employee-access-button"
                >{{
                    employee.suspended ? 'Przywróć dostęp' : 'Zawieś'
                }}</ActionButton
            >
        </template>
    </ConfirmDialog>
</template>
