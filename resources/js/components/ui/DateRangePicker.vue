<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CalendarDays, X } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Calendar from '@/components/ui/Calendar.vue';
import { usePopover } from '@/composables/usePopover';
import { fromIsoDate } from '@/lib/dates';
import { cn } from '@/lib/cn';
import type { DateRange } from '@/types/ui';
import type { SharedProps } from '@/types';

/**
 * A trigger that opens a calendar and reports an inclusive date range.
 *
 * The picker holds a DRAFT until Apply. A range is half-invalid between the two
 * clicks that make it, and firing a server reload on the first click would
 * fetch a range the user has not finished describing.
 *
 * The design places this behind "Custom" on the Orders period strip and behind
 * the "Date range ▾" toolbar filter (`455:1577`) but never draws it, so the
 * surface follows the app's own vocabulary — see Calendar.
 */
const props = withDefaults(
    defineProps<{
        modelValue: DateRange;
        label?: string;
        /** Future dates make no sense for order history; default is today. */
        max?: string | null;
        align?: 'left' | 'right';
        class?: string;
    }>(),
    { label: 'Date range', max: undefined, align: 'left' },
);

const emit = defineEmits<{ 'update:modelValue': [value: DateRange] }>();

const anchor = ref<HTMLElement | null>(null);
const { open, close, toggle } = usePopover(anchor);

const locale = computed(() => usePage<SharedProps>().props.locale);

const draft = ref<DateRange>({ ...props.modelValue });

watch(open, (isOpen) => {
    if (isOpen) draft.value = { ...props.modelValue };
});

const applied = computed(() => props.modelValue.from !== null || props.modelValue.to !== null);

function format(value: string | null): string {
    const date = fromIsoDate(value);

    return date === null
        ? '…'
        : date.toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: '2-digit' });
}

const summary = computed(() =>
    applied.value ? `${format(props.modelValue.from)} – ${format(props.modelValue.to)}` : props.label,
);

function apply(): void {
    emit('update:modelValue', { ...draft.value });
    close();
}

function clear(): void {
    draft.value = { from: null, to: null };
    emit('update:modelValue', { from: null, to: null });
    close();
}

/** Apply is only meaningful once both ends exist. */
const complete = computed(() => draft.value.from !== null && draft.value.to !== null);

/** Today, as the default ceiling — nothing this picker feeds is in the future. */
const defaultMax = new Date().toISOString().slice(0, 10);
</script>

<template>
    <div ref="anchor" class="relative">
        <button
            type="button"
            :aria-expanded="open"
            aria-haspopup="dialog"
            :class="
                cn(
                    'inline-flex h-8 items-center gap-1.5 border px-2.5 text-xs transition-colors',
                    applied
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-dashed border-border text-foreground hover:border-foreground/40',
                    props.class,
                )
            "
            @click="toggle"
        >
            <CalendarDays class="size-3.5" :stroke-width="1.5" aria-hidden="true" />
            {{ summary }}
            <span
                v-if="applied"
                role="button"
                tabindex="-1"
                aria-label="Clear date range"
                class="-mr-1 ml-1 p-0.5"
                @click.stop="clear"
            >
                <X class="size-3" :stroke-width="2" />
            </span>
        </button>

        <div
            v-if="open"
            role="dialog"
            :aria-label="label"
            class="absolute top-9 z-30 border border-border bg-card p-3 shadow-lg"
            :class="align === 'right' ? 'right-0' : 'left-0'"
        >
            <Calendar v-model="draft" mode="range" :max="max === undefined ? defaultMax : max" />

            <div class="mt-3 flex items-center justify-between gap-2 border-t border-border pt-3">
                <span class="text-2xs text-muted-foreground">
                    {{ draft.from === null ? 'Pick a start date' : `${format(draft.from)} – ${format(draft.to)}` }}
                </span>
                <span class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="clear">Clear</Button>
                    <Button size="sm" :disabled="!complete" @click="apply">Apply</Button>
                </span>
            </div>
        </div>
    </div>
</template>
