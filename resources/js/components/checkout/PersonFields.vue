<script setup lang="ts">
import FormField from '@/components/shared/FormField.vue';
import type { CheckoutPerson } from '@/types/checkout';

const person = defineModel<CheckoutPerson>({ required: true });
defineProps<{
    prefix: 'purchaser' | 'holder';
    errors: Record<string, string>;
    disabled: boolean;
    emailMaxLength?: number;
}>();
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <FormField
            :id="`${prefix}-first-name`"
            v-model="person.first_name"
            label="Imię *"
            required
            maxlength="120"
            :autocomplete="`section-${prefix} given-name`"
            :error="errors[`${prefix}.first_name`]"
            :disabled="disabled"
        />
        <FormField
            :id="`${prefix}-last-name`"
            v-model="person.last_name"
            label="Nazwisko *"
            required
            maxlength="120"
            :autocomplete="`section-${prefix} family-name`"
            :error="errors[`${prefix}.last_name`]"
            :disabled="disabled"
        />
        <FormField
            :id="`${prefix}-email`"
            v-model="person.email"
            label="Adres e-mail *"
            type="email"
            required
            :maxlength="emailMaxLength ?? (prefix === 'purchaser' ? 50 : 254)"
            :autocomplete="`section-${prefix} email`"
            :error="errors[`${prefix}.email`]"
            :disabled="disabled"
        />
        <FormField
            :id="`${prefix}-phone`"
            v-model="person.phone"
            label="Numer telefonu *"
            type="tel"
            inputmode="tel"
            required
            maxlength="24"
            :autocomplete="`section-${prefix} tel`"
            :error="errors[`${prefix}.phone`]"
            hint="Np. +48 600 111 222"
            :disabled="disabled"
        />
    </div>
</template>
