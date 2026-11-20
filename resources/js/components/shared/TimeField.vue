<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        modelValue: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    { required: false, disabled: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const describedBy = computed(
    () => `${props.id}-hint${props.error ? ` ${props.id}-error` : ''}`,
);
const hour = ref('');
const minute = ref('');
const hours = Array.from({ length: 24 }, (_, value) =>
    String(value).padStart(2, '0'),
);
const minutes = Array.from({ length: 60 }, (_, value) =>
    String(value).padStart(2, '0'),
);
watch(
    () => props.modelValue,
    (value) => {
        const parts = /^([01][0-9]|2[0-3]):([0-5][0-9])$/.exec(value);
        hour.value = parts?.[1] ?? '';
        minute.value = parts?.[2] ?? '';
    },
    { immediate: true },
);
function select(part: 'hour' | 'minute', event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    if (part === 'hour') hour.value = value;
    else minute.value = value;
    emit(
        'update:modelValue',
        hour.value && minute.value ? `${hour.value}:${minute.value}` : '',
    );
}
</script>
<template>
    <fieldset :disabled="disabled" class="grid min-w-0 gap-2">
        <legend class="mb-2 text-sm font-medium">{{ label }}</legend>
        <div class="flex min-w-0 items-center gap-2">
            <div class="min-w-0 flex-1">
                <Label :for="`${id}-hour`" class="sr-only"
                    >{{ label }} — godzina</Label
                >
                <select
                    :id="`${id}-hour`"
                    :value="hour"
                    :required="required"
                    :aria-invalid="!!error"
                    :aria-describedby="describedBy"
                    class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    @change="select('hour', $event)"
                >
                    <option value="" disabled>GG</option>
                    <option v-for="value in hours" :key="value" :value="value">
                        {{ value }}
                    </option>
                </select>
            </div>
            <span aria-hidden="true">:</span>
            <div class="min-w-0 flex-1">
                <Label :for="`${id}-minute`" class="sr-only"
                    >{{ label }} — minuty</Label
                >
                <select
                    :id="`${id}-minute`"
                    :value="minute"
                    :required="required"
                    :aria-invalid="!!error"
                    :aria-describedby="describedBy"
                    class="min-h-11 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    @change="select('minute', $event)"
                >
                    <option value="" disabled>MM</option>
                    <option
                        v-for="value in minutes"
                        :key="value"
                        :value="value"
                    >
                        {{ value }}
                    </option>
                </select>
            </div>
        </div>
        <p :id="`${id}-hint`" class="text-sm text-muted-foreground">
            Format 24-godzinny.
        </p>
        <InputError :id="`${id}-error`" :message="error" />
    </fieldset>
</template>
