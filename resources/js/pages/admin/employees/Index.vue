<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmployeeController from '@/actions/App/Http/Controllers/Admin/EmployeeController';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import { edit as profile } from '@/routes/profile';
import type { PaginationLink } from '@/types';

defineProps<{
    employees: {
        data: {
            id: number;
            name: string;
            email: string;
            phone: string | null;
            role: 'admin' | 'employee';
            suspended: boolean;
            invitationPending: boolean;
            canManage: boolean;
        }[];
        links: PaginationLink[];
        total: number;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pracownicy', href: EmployeeController.index() },
        ],
    },
});
</script>
<template>
    <Head title="Pracownicy" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Pracownicy</h1>
                <p class="text-sm text-muted-foreground">
                    Zarządzaj kontami zespołu i dostępem do panelu.
                </p>
            </div>
            <Button as-child
                ><Link :href="EmployeeController.create()"
                    >Dodaj konto</Link
                ></Button
            >
        </div>
        <EmptyState
            v-if="!employees.data.length"
            title="Brak kont zespołu"
            description="Dodaj konto i zaproś pracownika do ustawienia własnego hasła."
        />
        <ul v-else class="grid gap-4" aria-label="Konta zespołu">
            <li
                v-for="employee in employees.data"
                :key="employee.id"
                class="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 space-y-1 break-words">
                    <h2 class="font-semibold">{{ employee.name }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            employee.role === 'admin'
                                ? 'Administrator'
                                : 'Pracownik'
                        }}
                        ·
                        {{
                            employee.suspended
                                ? 'Dostęp zawieszony'
                                : 'Dostęp włączony'
                        }}
                    </p>
                    <p class="text-sm">{{ employee.email }}</p>
                    <p class="text-sm">
                        {{ employee.phone || 'Brak telefonu' }}
                    </p>
                    <p
                        v-if="employee.invitationPending"
                        class="text-sm text-muted-foreground"
                    >
                        Oczekuje na ustawienie hasła
                    </p>
                </div>
                <Button v-if="employee.canManage" as-child variant="outline"
                    ><Link :href="EmployeeController.edit(employee.id)"
                        >Edytuj<span class="sr-only">
                            konto {{ employee.name }}</span
                        ></Link
                    ></Button
                >
                <Button v-else as-child variant="outline"
                    ><Link :href="profile()">Mój profil</Link></Button
                >
            </li>
        </ul>
        <Pagination :links="employees.links" />
    </div>
</template>
