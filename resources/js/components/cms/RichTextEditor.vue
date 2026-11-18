<script setup lang="ts">
import { onMounted, ref, useId, watch } from 'vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import FormField from '@/components/shared/FormField.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogClose,
    DialogTrigger,
} from '@/components/ui/dialog';

const props = withDefaults(
    defineProps<{ error?: string; disabled?: boolean }>(),
    { disabled: false },
);
const model = defineModel<string>({ required: true });
const id = useId();
const editor = ref<HTMLDivElement>();
const linkOpen = ref(false);
const linkUrl = ref('');
const linkError = ref('');
let savedRange: Range | null = null;
const commands = [
    { command: 'bold', label: 'Pogrubienie', text: 'B' },
    { command: 'italic', label: 'Kursywa', text: 'I' },
    { command: 'underline', label: 'Podkreślenie', text: 'U' },
    {
        command: 'insertUnorderedList',
        label: 'Lista punktowana',
        text: '• Lista',
    },
    {
        command: 'insertOrderedList',
        label: 'Lista numerowana',
        text: '1. Lista',
    },
];
function sync() {
    if (editor.value) model.value = editor.value.innerHTML;
    remember();
}
function remember() {
    const selection = window.getSelection();
    if (
        selection?.rangeCount &&
        editor.value?.contains(selection.anchorNode) &&
        editor.value.contains(selection.focusNode)
    )
        savedRange = selection.getRangeAt(0).cloneRange();
}
function restore() {
    editor.value?.focus();
    if (
        savedRange &&
        editor.value?.contains(savedRange.commonAncestorContainer)
    ) {
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(savedRange);
    }
}
function command(name: string, value?: string) {
    if (props.disabled) return;
    restore();
    // Native editing commands preserve the browser's undo/redo history.
    document.execCommand(name, false, value);
    sync();
}
function paste(event: ClipboardEvent) {
    command('insertText', event.clipboardData?.getData('text/plain') ?? '');
}
function drop(event: DragEvent) {
    command('insertText', event.dataTransfer?.getData('text/plain') ?? '');
}
function safeUrl(value: string) {
    if (
        Array.from(value).some(
            (char) =>
                char.charCodeAt(0) <= 32 ||
                char.charCodeAt(0) === 127 ||
                char === '\\',
        )
    )
        return false;
    if (/^\/(?!\/)[a-zA-Z0-9/_.?#&=%+~-]*$/u.test(value)) return true;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.password;
    } catch {
        return false;
    }
}
function addLink() {
    const url = linkUrl.value.trim();
    if (!safeUrl(url)) {
        linkError.value =
            'Podaj adres https://… lub podstronę tego serwisu, np. /kontakt.';
        return;
    }
    linkOpen.value = false;
    command('createLink', url);
}
onMounted(() => {
    if (editor.value) editor.value.innerHTML = model.value;
});
watch(model, (value) => {
    if (editor.value && editor.value.innerHTML !== value)
        editor.value.innerHTML = value;
});
</script>
<template>
    <div class="grid min-w-0 gap-2">
        <p :id="`${id}-label`" class="text-sm font-semibold">
            Treść aktualności
        </p>
        <div
            class="min-w-0 overflow-hidden rounded-xl border border-input bg-card"
        >
            <div
                role="group"
                aria-label="Formatowanie tekstu"
                class="flex flex-wrap gap-1 border-b border-border bg-muted p-2"
            >
                <ActionButton
                    v-for="item in commands"
                    :key="item.command"
                    variant="outline"
                    :disabled="disabled"
                    :aria-label="item.label"
                    :title="item.label"
                    :data-command="item.command"
                    @mousedown.prevent
                    @click="command(item.command)"
                    >{{ item.text }}</ActionButton
                >
                <label class="sr-only" :for="`${id}-block`"
                    >Rodzaj akapitu</label
                ><select
                    :id="`${id}-block`"
                    class="min-h-11 max-w-full rounded-lg border border-input bg-background px-2 text-sm"
                    :disabled="disabled"
                    @change="
                        command(
                            'formatBlock',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="p">Akapit</option>
                    <option value="h2">Nagłówek</option>
                    <option value="h3">Podtytuł</option>
                </select>
                <Dialog v-model:open="linkOpen"
                    ><DialogTrigger as-child
                        ><ActionButton
                            variant="outline"
                            :disabled="disabled"
                            @mousedown.prevent
                            @click="
                                linkUrl = '';
                                linkError = '';
                            "
                            >Dodaj link</ActionButton
                        ></DialogTrigger
                    ><DialogContent
                        ><DialogHeader
                            ><DialogTitle>Dodaj odnośnik</DialogTitle
                            ><DialogDescription
                                >Najpierw zaznacz tekst, który ma prowadzić do
                                wybranej strony.</DialogDescription
                            ></DialogHeader
                        ><FormField
                            :id="`${id}-url`"
                            v-model="linkUrl"
                            label="Adres strony"
                            :error="linkError"
                            placeholder="https://… lub /kontakt"
                            @keydown.enter.prevent="addLink"
                        /><DialogFooter
                            ><DialogClose as-child
                                ><ActionButton variant="outline"
                                    >Anuluj</ActionButton
                                ></DialogClose
                            ><ActionButton @click="addLink"
                                >Dodaj odnośnik</ActionButton
                            ></DialogFooter
                        ></DialogContent
                    ></Dialog
                >
                <ActionButton
                    variant="outline"
                    :disabled="disabled"
                    @mousedown.prevent
                    @click="command('unlink')"
                    >Usuń link</ActionButton
                >
            </div>
            <div
                :id="id"
                ref="editor"
                role="textbox"
                aria-multiline="true"
                :aria-labelledby="`${id}-label`"
                :aria-describedby="`${id}-hint ${id}-error`"
                :aria-invalid="!!error"
                :aria-disabled="disabled"
                :contenteditable="!disabled"
                class="rich-text max-h-[60vh] min-h-64 max-w-full overflow-y-auto p-4 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                @input="sync"
                @keyup="remember"
                @mouseup="remember"
                @focusout="remember"
                @paste.prevent="paste"
                @drop.prevent="drop"
            />
        </div>
        <p :id="`${id}-hint`" class="text-sm text-muted-foreground">
            Możesz użyć akapitów, nagłówków, list, pogrubienia i linków.
            Wklejany tekst nie zachowuje obcego formatowania. Zdjęcie dodaj
            poniżej.
        </p>
        <InputError :id="`${id}-error`" :message="error" />
    </div>
</template>
