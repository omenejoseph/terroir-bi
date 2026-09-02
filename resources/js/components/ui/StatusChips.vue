<script setup lang="ts">
import { cn } from '@/lib/cn';

/**
 * The status filter row above a list (Figma 455:1577: "All 37 · ▢ Received 2 ·
 * ◼ In Process 4 · ◼ Ready to Ship 6 · ▨ Shipped 25").
 *
 * Each chip is a 25px bordered button carrying a small square swatch, the label
 * and the count. The active chip inverts to a solid dark fill. The swatch tone
 * walks from outline through solid to muted, encoding progress through the
 * workflow — so the row reads as a sequence at a glance, not just five filters.
 *
 * `null` in `select` means "clear the filter", which is what pressing the
 * active chip or "All" does.
 */
export interface StatusChip {
    key: string;
    label: string;
    count: number;
}

const props = defineProps<{
    chips: StatusChip[];
    /** Null while no status is selected — the "All" chip is then active. */
    current: string | null;
    /** Count for the "All" chip. */
    total: number;
    allLabel?: string;
}>();

const emit = defineEmits<{ select: [key: string | null] }>();

/*
  Swatch tones in workflow order. Anything past the fifth chip repeats the last
  tone rather than throwing — the row must not break if a status is added.
*/
const TONES = [
    'border border-foreground bg-transparent',
    'bg-foreground',
    'bg-foreground',
    'bg-muted-foreground/40',
];

function chipClass(active: boolean): string {
    return cn(
        'inline-flex h-[25px] shrink-0 items-center gap-2 border px-2.5 text-xs transition-colors',
        active
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-border bg-card text-foreground hover:border-foreground/40',
    );
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by status">
        <button
            type="button"
            :class="chipClass(props.current === null)"
            :aria-pressed="props.current === null"
            @click="emit('select', null)"
        >
            <span>{{ props.allLabel ?? 'All' }}</span>
            <span class="tabular-nums opacity-60">{{ props.total }}</span>
        </button>

        <button
            v-for="(chip, i) in props.chips"
            :key="chip.key"
            type="button"
            :class="chipClass(props.current === chip.key)"
            :aria-pressed="props.current === chip.key"
            @click="emit('select', props.current === chip.key ? null : chip.key)"
        >
            <span
                class="size-2 shrink-0"
                :class="props.current === chip.key ? 'bg-primary-foreground' : (TONES[i] ?? TONES[TONES.length - 1])"
                aria-hidden="true"
            />
            <span>{{ chip.label }}</span>
            <span class="tabular-nums opacity-60">{{ chip.count }}</span>
        </button>
    </div>
</template>
