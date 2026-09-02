<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Check, ChevronDown, X } from 'lucide-vue-next';

import { usePopover } from '@/composables/usePopover';
import { cn } from '@/lib/cn';
import type { ComboboxOption } from '@/types/ui';

/**
 * A select you can type into.
 *
 * The design asks for this by name — "Search product by name, SKU or vintage…"
 * on `335:4233` — and a native `<select>` cannot do it. A winery with a few
 * hundred SKUs cannot scroll a flat option list to find one, so the trigger
 * turns into a search field when it opens and the list narrows as you type.
 *
 * Popup geometry from `267:6332` (SelectPopup): the panel matches the trigger's
 * width, options are 32px tall with 12px labels, and the selected one carries a
 * 16px check.
 *
 * Matching is on label AND on each option's `keywords`, so a product can be
 * found by SKU or vintage without either being printed in its label.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        options: ComboboxOption[];
        id?: string;
        placeholder?: string;
        /** Message when the query matches nothing. */
        emptyText?: string;
        invalid?: boolean;
        disabled?: boolean;
        /** Show a clear control once something is selected. */
        clearable?: boolean;
        class?: string;
    }>(),
    { placeholder: 'Select…', emptyText: 'Nothing matches.', clearable: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const anchor = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const { open, show, close, toggle } = usePopover(anchor);

const query = ref('');
const activeIndex = ref(0);
const listId = useId();

const selected = computed(() => props.options.find((o) => o.value === props.modelValue) ?? null);

const filtered = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (term === '') return props.options;

    return props.options.filter((option) => {
        const haystack = [option.label, option.description, ...(option.keywords ?? [])]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(term);
    });
});

/* Reopening starts from a clean query, with the cursor on what is selected. */
watch(open, async (isOpen) => {
    if (!isOpen) {
        query.value = '';

        return;
    }

    activeIndex.value = Math.max(
        0,
        filtered.value.findIndex((o) => o.value === props.modelValue),
    );

    await nextTick();
    input.value?.focus();
});

/* Typing resets the cursor to the top of what is left. */
watch(query, () => {
    activeIndex.value = 0;
});

function move(delta: number): void {
    if (filtered.value.length === 0) return;

    const next = (activeIndex.value + delta + filtered.value.length) % filtered.value.length;
    activeIndex.value = next;

    // Keep the cursor visible without scrolling the page.
    nextTick(() => {
        list.value?.querySelector(`[data-index="${next}"]`)?.scrollIntoView({ block: 'nearest' });
    });
}

function choose(option: ComboboxOption | undefined): void {
    if (option === undefined || option.disabled) return;

    emit('update:modelValue', option.value);
    close();
}

function clear(): void {
    emit('update:modelValue', null);
}

function onKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            show();
        }

        return;
    }

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Home':
            event.preventDefault();
            activeIndex.value = 0;
            break;
        case 'End':
            event.preventDefault();
            activeIndex.value = Math.max(0, filtered.value.length - 1);
            break;
        case 'Enter':
            event.preventDefault();
            choose(filtered.value[activeIndex.value]);
            break;
        case 'Tab':
            close({ restoreFocus: false });
            break;
    }
}
</script>

<template>
    <div ref="anchor" class="relative">
        <!--
          One control in two states: a button showing the selection, and, once
          open, the search field itself. Swapping keeps the trigger's box
          identical either way, so the layout does not shift on open.
        -->
        <button
            v-if="!open"
            :id="id"
            type="button"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            aria-haspopup="listbox"
            :aria-expanded="false"
            :class="
                cn(
                    'flex h-9 w-full items-center gap-2 border bg-card px-3 text-left text-sm transition-colors',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    invalid ? 'border-destructive' : 'border-input',
                    props.class,
                )
            "
            @click="toggle"
            @keydown="onKeydown"
        >
            <span class="min-w-0 flex-1 truncate" :class="selected === null && 'text-muted-foreground'">
                {{ selected?.label ?? placeholder }}
            </span>

            <span
                v-if="clearable && selected !== null"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                role="button"
                tabindex="-1"
                aria-label="Clear selection"
                @click.stop="clear"
            >
                <X class="size-3.5" :stroke-width="1.5" />
            </span>

            <ChevronDown class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" aria-hidden="true" />
        </button>

        <div
            v-else
            :class="
                cn(
                    'flex h-9 w-full items-center gap-2 border bg-card px-3',
                    invalid ? 'border-destructive' : 'border-ring',
                    props.class,
                )
            "
        >
            <input
                :id="id"
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="true"
                :aria-controls="listId"
                :aria-activedescendant="filtered.length > 0 ? `${listId}-${activeIndex}` : undefined"
                :placeholder="selected?.label ?? placeholder"
                class="min-w-0 flex-1 bg-transparent text-sm placeholder:text-muted-foreground focus-visible:outline-none"
                @keydown="onKeydown"
            />
            <ChevronDown class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" aria-hidden="true" />
        </div>

        <div
            v-if="open"
            :id="listId"
            ref="list"
            role="listbox"
            class="absolute top-10 right-0 left-0 z-30 max-h-64 overflow-y-auto border border-border bg-card shadow-lg"
        >
            <button
                v-for="(option, i) in filtered"
                :id="`${listId}-${i}`"
                :key="option.value"
                :data-index="i"
                type="button"
                role="option"
                :aria-selected="option.value === modelValue"
                :disabled="option.disabled"
                class="flex h-8 w-full items-center gap-2 px-3 text-left text-xs transition-colors disabled:opacity-40"
                :class="i === activeIndex ? 'bg-muted' : 'hover:bg-muted/60'"
                @click="choose(option)"
                @mousemove="activeIndex = i"
            >
                <span class="min-w-0 flex-1 truncate">
                    {{ option.label }}
                    <span v-if="option.description" class="ml-2 text-muted-foreground">
                        {{ option.description }}
                    </span>
                </span>
                <Check
                    v-if="option.value === modelValue"
                    class="size-4 shrink-0"
                    :stroke-width="2"
                    aria-hidden="true"
                />
            </button>

            <p v-if="filtered.length === 0" class="px-3 py-3 text-xs text-muted-foreground">
                {{ emptyText }}
            </p>
        </div>
    </div>
</template>
