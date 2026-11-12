<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';
import type { AuthenticatedPageProps, DashboardPageProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Moje konto',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<DashboardPageProps>();
const page = usePage<AuthenticatedPageProps>();
const user = computed(() => page.props.auth.user);
const accountCreatedOn = computed(() =>
    props.accountCreatedAt
        ? new Intl.DateTimeFormat('pl-PL', {
              dateStyle: 'long',
              timeZone: page.props.businessTimezone,
          }).format(new Date(props.accountCreatedAt))
        : 'Brak informacji',
);
</script>

<template>
    <Head title="Moje konto" />

    <div class="flex flex-1 flex-col gap-6">
        <h1 class="text-2xl font-semibold">Moje konto</h1>

        <section
            aria-labelledby="account-details-heading"
            class="w-full max-w-2xl rounded-2xl border border-border bg-card p-4 sm:p-6"
        >
            <h2 id="account-details-heading" class="text-lg font-medium">
                Dane Twojego konta
            </h2>

            <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        Imię i nazwisko
                    </dt>
                    <dd class="mt-1 break-words">{{ user.name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">Adres e-mail</dt>
                    <dd class="mt-1 break-all">{{ user.email }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        Data utworzenia konta
                    </dt>
                    <dd class="mt-1">
                        <time
                            v-if="accountCreatedAt"
                            :datetime="accountCreatedAt"
                        >
                            {{ accountCreatedOn }}
                        </time>
                        <span v-else>{{ accountCreatedOn }}</span>
                    </dd>
                </div>
            </dl>

            <Button as-child class="mt-6">
                <Link :href="edit()">Edytuj profil</Link>
            </Button>
        </section>
    </div>
</template>
