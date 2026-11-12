<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({ inheritAttrs: false });
const props = defineProps<{
    id: string;
    label: string;
    name?: string;
    modelValue?: string | number;
    defaultValue?: string | number;
    hint?: string;
    error?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();
const describedBy = computed(
    () =>
        [props.hint && `${props.id}-hint`, props.error && `${props.id}-error`]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            v-bind="$attrs"
            :id="id"
            :name="name ?? id"
            :model-value="modelValue"
            :default-value="defaultValue"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
            class="min-h-11 bg-card"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <p v-if="hint" :id="`${id}-hint`" class="text-sm text-muted-foreground">
            {{ hint }}
        </p>
        <InputError :id="`${id}-error`" :message="error" />
    </div>
</template>
