<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import type { HomePageProps } from '@/types';
defineProps<HomePageProps>();
const page = usePage();
</script>

<template>
    <Head title="Witaj w ActiveFit"
        ><meta
            name="description"
            content="Twoje konto w ActiveFit. Zaloguj się lub załóż konto."
    /></Head>
    <section
        class="mx-auto max-w-2xl rounded-2xl border border-border bg-card p-6 sm:p-10"
        aria-labelledby="welcome-title"
    >
        <p
            class="text-xs font-bold tracking-[0.16em] text-muted-foreground uppercase"
        >
            ActiveFit
        </p>
        <h1
            id="welcome-title"
            class="mt-4 text-3xl leading-tight font-bold tracking-tight sm:text-4xl"
        >
            Twoje konto w ActiveFit
        </h1>
        <p class="mt-5 text-muted-foreground">
            {{
                page.props.auth.user
                    ? 'Przejdź do swojego konta, aby sprawdzić i zaktualizować dane.'
                    : 'Zaloguj się, aby przejść do swojego konta.'
            }}
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <Button v-if="page.props.auth.user" as-child
                ><Link :href="dashboard()">Przejdź do konta</Link></Button
            >
            <template v-else>
                <Button as-child
                    ><Link :href="login()">Zaloguj się</Link></Button
                >
                <Button v-if="canRegister" variant="outline" as-child
                    ><Link :href="register()">Załóż konto</Link></Button
                >
            </template>
        </div>
    </section>
</template>
