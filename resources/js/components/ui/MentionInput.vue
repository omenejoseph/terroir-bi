<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';

import { usePopover } from '@/composables/usePopover';
import { cn } from '@/lib/cn';

/**
 * A plain text field with inline `@`-mention autocomplete, ported from the
 * outgoing React app's `mention-input.tsx` (algorithm) onto this app's own
 * popover/dropdown conventions (`usePopover`, absolute-positioned — not a
 * portal, matching every other dropdown here since a fixed-position one
 * breaks under the iOS keyboard). A single-line `<input>`, not a `<textarea>`
 * — it's a drop-in for an existing input, styled by the caller via `class`,
 * not a new field of its own; `selectionStart`/`setSelectionRange` work
 * identically on either element, so nothing about the algorithm needs a
 * textarea.
 *
 * Typing `@` at the start of the text or right after whitespace opens a
 * dropdown of the tenant's members, filtered locally by name/email (the list
 * is small — fetched once, not searched server-side). Arrow keys move,
 * Enter/Tab inserts `@Name ` and tracks the mention out of band; deleting the
 * inserted text un-mentions them. `mentions` is recomputed from what's still
 * literally present in the text whenever it changes.
 */
export interface Mentionable {
    id: string;
    name: string;
    email: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        members: Mentionable[];
        placeholder?: string;
        id?: string;
        ariaLabel?: string;
        class?: string;
    }>(),
    { placeholder: 'Write a comment…' },
);

const emit = defineEmits<{ 'update:modelValue': [string]; mentions: [string[]] }>();

const anchor = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const { open, show, close } = usePopover(anchor);
const listId = useId();

const query = ref<string | null>(null);
const anchorIndex = ref(0);
const activeIndex = ref(0);

/** user_id -> the name text that was inserted for them, so it can be found (or not) in later edits. */
const mentioned = new Map<string, string>();

const filtered = computed(() => {
    if (query.value === null) return [];

    const term = query.value.toLowerCase();

    return props.members
        .filter((m) => m.name.toLowerCase().includes(term) || m.email.toLowerCase().includes(term))
        .slice(0, 8);
});

watch(filtered, () => (activeIndex.value = 0));

/** An "@token" immediately before the caret — start of text, or right after whitespace, with no whitespace since. */
function detect(text: string, caret: number): void {
    const before = text.slice(0, caret);
    const at = before.lastIndexOf('@');

    if (at === -1) {
        query.value = null;

        return;
    }

    const precedingChar = at === 0 ? '' : before[at - 1];
    const token = before.slice(at + 1);

    if ((at === 0 || /\s/.test(precedingChar ?? '')) && !/\s/.test(token)) {
        anchorIndex.value = at;
        query.value = token;
        show();
    } else {
        query.value = null;
    }
}

function emitMentions(text: string): void {
    const ids = [...mentioned.entries()].filter(([, name]) => text.includes(`@${name}`)).map(([id]) => id);

    emit('mentions', ids);
}

function onInput(event: Event): void {
    const el = event.target as HTMLInputElement;

    emit('update:modelValue', el.value);
    detect(el.value, el.selectionStart ?? el.value.length);
    emitMentions(el.value);
}

function select(member: Mentionable): void {
    const el = input.value;
    const caret = el?.selectionStart ?? props.modelValue.length;
    const before = props.modelValue.slice(0, anchorIndex.value);
    const after = props.modelValue.slice(caret);
    const insert = `@${member.name} `;
    const next = before + insert + after;

    mentioned.set(member.id, member.name);
    emit('update:modelValue', next);
    emitMentions(next);
    close({ restoreFocus: false });
    query.value = null;

    const caretAt = before.length + insert.length;
    nextTick(() => {
        el?.focus();
        el?.setSelectionRange(caretAt, caretAt);
    });
}

function move(delta: number): void {
    if (filtered.value.length === 0) return;

    activeIndex.value = (activeIndex.value + delta + filtered.value.length) % filtered.value.length;
}

function onKeydown(event: KeyboardEvent): void {
    if (query.value === null || filtered.value.length === 0) return;

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter':
        case 'Tab': {
            const member = filtered.value[activeIndex.value];
            if (member === undefined) return;
            event.preventDefault();
            select(member);
            break;
        }
        case 'Escape':
            event.preventDefault();
            query.value = null;
            close({ restoreFocus: false });
            break;
    }
}

/** An emptied field has nothing left to mention. */
watch(
    () => props.modelValue,
    (value) => {
        if (value === '') mentioned.clear();
    },
);

onMounted(() => {
    if (props.modelValue === '') mentioned.clear();
});
</script>

<template>
    <div ref="anchor" class="relative min-w-0 flex-1">
        <input
            :id="id"
            ref="input"
            type="text"
            :value="modelValue"
            :placeholder="placeholder"
            :aria-label="ariaLabel"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open && filtered.length > 0"
            :aria-controls="listId"
            :class="cn('w-full bg-transparent focus-visible:outline-none', props.class)"
            @input="onInput"
            @keydown="onKeydown"
        />

        <ul
            v-if="open && filtered.length > 0"
            :id="listId"
            role="listbox"
            class="absolute bottom-full left-0 z-30 mb-1 max-h-56 w-64 overflow-y-auto border border-border bg-card shadow-lg"
        >
            <li v-for="(member, i) in filtered" :key="member.id">
                <button
                    type="button"
                    role="option"
                    :aria-selected="i === activeIndex"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs transition-colors"
                    :class="i === activeIndex ? 'bg-muted' : 'hover:bg-muted/60'"
                    @mousedown.prevent="select(member)"
                    @mouseenter="activeIndex = i"
                >
                    <span class="font-medium">{{ member.name }}</span>
                    <span class="truncate text-muted-foreground">{{ member.email }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
