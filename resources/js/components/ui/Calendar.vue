<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

import {
    addMonths,
    fromIsoDate,
    isSameDay,
    isWithin,
    monthGrid,
    startOfMonth,
    toIsoDate,
    weekdayLabels,
} from '@/lib/dates';
import type { DateRange } from '@/types/ui';
import type { SharedProps } from '@/types';

/**
 * A month grid that selects a single date or an inclusive range.
 *
 * The Figma canvas has no calendar — the design asks for a "Custom" range and
 * a "Date range" filter but never draws the surface behind them. So this
 * follows the design's own vocabulary rather than inventing one: square cells,
 * 12px type, the same primary fill the active tab and chip use, and the range
 * shaded with the muted token between its ends.
 *
 * Range selection is the two-click model people expect: the first click sets
 * the start and clears the end, the second closes the range, and hovering
 * between them previews it. Clicking a date before the start restarts rather
 * than producing a backwards range.
 */
const props = withDefaults(
    defineProps<{
        /** `YYYY-MM-DD`, or a range when `mode` is 'range'. */
        modelValue: string | DateRange | null;
        mode?: 'single' | 'range';
        /** Nothing after this date can be picked — e.g. no future order dates. */
        max?: string | null;
        min?: string | null;
    }>(),
    { mode: 'single', max: null, min: null },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | DateRange | null] }>();

const locale = computed(() => usePage<SharedProps>().props.locale);

const range = computed<DateRange>(() =>
    props.mode === 'range' && props.modelValue !== null && typeof props.modelValue === 'object'
        ? props.modelValue
        : { from: null, to: null },
);

const single = computed(() =>
    props.mode === 'single' && typeof props.modelValue === 'string' ? props.modelValue : null,
);

/** Open on the month holding the current selection, else this month. */
const cursor = ref(
    startOfMonth(
        fromIsoDate(props.mode === 'range' ? range.value.from : single.value) ?? new Date(),
    ),
);

watch(
    () => props.modelValue,
    (value) => {
        const anchor = fromIsoDate(
            props.mode === 'range' && value !== null && typeof value === 'object' ? value.from : (value as string | null),
        );

        if (anchor !== null) cursor.value = startOfMonth(anchor);
    },
);

const hovered = ref<Date | null>(null);

const grid = computed(() => monthGrid(cursor.value, locale.value));
const weekdays = computed(() => weekdayLabels(locale.value));

const monthLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(cursor.value),
);

const minDate = computed(() => fromIsoDate(props.min));
const maxDate = computed(() => fromIsoDate(props.max));

function disabled(date: Date): boolean {
    if (minDate.value !== null && toIsoDate(date) < toIsoDate(minDate.value)) return true;
    if (maxDate.value !== null && toIsoDate(date) > toIsoDate(maxDate.value)) return true;

    return false;
}

const from = computed(() => fromIsoDate(range.value.from));
const to = computed(() => fromIsoDate(range.value.to));

/** While one end is set and the pointer is moving, preview the range. */
const previewTo = computed(() => (to.value === null ? hovered.value : to.value));

function isEnd(date: Date): boolean {
    return isSameDay(date, from.value) || isSameDay(date, to.value);
}

function inRange(date: Date): boolean {
    return isWithin(date, from.value, previewTo.value) && !isEnd(date);
}

function select(date: Date): void {
    if (disabled(date)) return;

    if (props.mode === 'single') {
        emit('update:modelValue', toIsoDate(date));

        return;
    }

    // Starting fresh, or restarting because the click landed before the start.
    if (from.value === null || to.value !== null || date < from.value) {
        emit('update:modelValue', { from: toIsoDate(date), to: null });

        return;
    }

    emit('update:modelValue', { from: range.value.from, to: toIsoDate(date) });
}

const today = toIsoDate(new Date());
</script>

<template>
    <div class="w-[17.5rem] select-none">
        <div class="flex items-center justify-between gap-2 px-1 pb-3">
            <button
                type="button"
                class="p-1 text-muted-foreground transition-colors hover:text-foreground"
                aria-label="Previous month"
                @click="cursor = addMonths(cursor, -1)"
            >
                <ChevronLeft class="size-4" :stroke-width="1.5" />
            </button>
            <span class="text-xs font-medium">{{ monthLabel }}</span>
            <button
                type="button"
                class="p-1 text-muted-foreground transition-colors hover:text-foreground"
                aria-label="Next month"
                @click="cursor = addMonths(cursor, 1)"
            >
                <ChevronRight class="size-4" :stroke-width="1.5" />
            </button>
        </div>

        <div class="grid grid-cols-7 gap-y-1" role="grid" @pointerleave="hovered = null">
            <span
                v-for="day in weekdays"
                :key="day"
                class="grid h-8 place-items-center text-2xs text-muted-foreground"
                role="columnheader"
            >
                {{ day }}
            </span>

            <button
                v-for="cell in grid"
                :key="cell.date.toISOString()"
                type="button"
                role="gridcell"
                :disabled="disabled(cell.date)"
                :aria-selected="isEnd(cell.date) || (mode === 'single' && single === toIsoDate(cell.date))"
                :aria-current="toIsoDate(cell.date) === today ? 'date' : undefined"
                class="grid h-8 place-items-center text-xs tabular-nums transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                :class="[
                    !cell.inMonth && 'text-muted-foreground/50',
                    inRange(cell.date) && 'bg-muted',
                    (isEnd(cell.date) || (mode === 'single' && single === toIsoDate(cell.date)))
                        ? 'bg-primary font-semibold text-primary-foreground'
                        : 'hover:bg-muted',
                    toIsoDate(cell.date) === today &&
                        !isEnd(cell.date) &&
                        single !== toIsoDate(cell.date) &&
                        'font-semibold underline underline-offset-4',
                ]"
                @click="select(cell.date)"
                @pointerenter="hovered = cell.date"
            >
                {{ cell.date.getDate() }}
            </button>
        </div>
    </div>
</template>
