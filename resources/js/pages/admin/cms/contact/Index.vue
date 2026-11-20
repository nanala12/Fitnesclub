<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ContactSettingsController from '@/actions/App/Http/Controllers/Admin/Cms/ContactSettingsController';
import { Button } from '@/components/ui/button';
import { contactFields } from '@/types/club';
import type { ClubContact, OpeningDay } from '@/types/club';
defineProps<{ contact: ClubContact; hours: OpeningDay[]; revision: number }>();
</script>
<template>
    <Head title="Kontakt i godziny" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="font-heading text-3xl">Kontakt i godziny</h1>
            <Button as-child
                ><Link :href="ContactSettingsController.edit()"
                    >Edytuj dane i godziny</Link
                ></Button
            >
        </div>
        <p class="text-sm text-muted-foreground">
            Zapisane informacje są widoczne na stronie kontaktowej i w stopce
            klubu.
        </p>
        <div class="grid min-w-0 gap-6 lg:grid-cols-2">
            <section
                class="min-w-0 space-y-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
                aria-labelledby="contact-heading"
            >
                <h2 id="contact-heading" class="font-heading text-2xl">
                    Dane klubu
                </h2>
                <dl class="space-y-4">
                    <div v-for="field in contactFields" :key="field.key">
                        <dt class="text-sm text-muted-foreground">
                            {{ field.label }}
                        </dt>
                        <dd class="mt-1 break-words">
                            {{ contact[field.key] || 'Nie uzupełniono' }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-4 sm:p-6"
                aria-labelledby="hours-heading"
            >
                <h2 id="hours-heading" class="font-heading text-2xl">
                    Godziny otwarcia
                </h2>
                <dl class="space-y-4">
                    <div
                        v-for="day in hours"
                        :key="day.weekday"
                        class="flex flex-wrap justify-between gap-2 border-b border-border pb-3"
                    >
                        <dt>{{ day.label }}</dt>
                        <dd class="font-semibold">
                            {{
                                day.status === 'closed'
                                    ? 'Nieczynne'
                                    : day.status === 'open'
                                      ? `${day.opens_at}–${day.closes_at}`
                                      : 'Brak informacji'
                            }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
        <Link
            href="/kontakt"
            class="inline-flex min-h-11 items-center underline"
            >Zobacz kontakt na stronie klubu</Link
        >
    </div>
</template>
