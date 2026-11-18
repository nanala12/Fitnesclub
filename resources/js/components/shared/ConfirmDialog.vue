<script setup lang="ts">
import { ref, useSlots, watch } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
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

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        confirmLabel: string;
        processing?: boolean;
        error?: string;
        destructive?: boolean;
    }>(),
    { processing: false, destructive: false },
);
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();
const slots = useSlots();
const returnFocus = ref<HTMLElement | null>(null);
watch(
    open,
    (value) => {
        if (
            value &&
            typeof document !== 'undefined' &&
            document.activeElement instanceof HTMLElement
        ) {
            returnFocus.value = document.activeElement;
        }
    },
    { flush: 'sync' },
);
function restoreFocus(event: Event) {
    if (!slots.trigger && returnFocus.value?.isConnected) {
        event.preventDefault();
        returnFocus.value.focus();
    }
}

function updateOpen(value: boolean) {
    if (!props.processing) open.value = value;
}
function preventWhileProcessing(event: Event) {
    if (props.processing) event.preventDefault();
}
</script>

<template>
    <Dialog :open="open" @update:open="updateOpen">
        <DialogTrigger v-if="$slots.trigger" as-child
            ><slot name="trigger"
        /></DialogTrigger>
        <DialogContent
            :show-close-button="false"
            class="max-h-[90dvh] overflow-y-auto rounded-2xl bg-card"
            @close-auto-focus="restoreFocus"
            @escape-key-down="preventWhileProcessing"
            @interact-outside="preventWhileProcessing"
        >
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <FeedbackMessage v-if="error" variant="error" :title="error" />
            <DialogFooter class="gap-2">
                <DialogClose as-child
                    ><ActionButton variant="outline" :disabled="processing"
                        >Anuluj</ActionButton
                    ></DialogClose
                >
                <ActionButton
                    :variant="destructive ? 'destructive' : 'default'"
                    :loading="processing"
                    @click="emit('confirm')"
                    >{{ confirmLabel }}</ActionButton
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
