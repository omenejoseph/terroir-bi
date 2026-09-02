<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CalendarDays, CircleCheck, Container } from 'lucide-vue-next';

import Avatar from '@/components/ui/Avatar.vue';
import { cn } from '@/lib/cn';
import { categoryLabel, categoryTone, isOverdue, PRIORITY_TONES } from '@/lib/work-orders';
import type { WorkOrder } from '@/types/work-orders';
import type { SharedProps } from '@/types';

/**
 * One card on the board (Figma 267:1781).
 *
 * The card is the only place the design uses colour, and it earns it: the
 * category tag says what kind of work this is and the priority tag says how
 * badly it is wanted, both readable without stopping to read the title. An
 * overdue card outlines itself in red and says so on its date line, because a
 * date that has passed is the one thing on this board that needs acting on.
 */
const props = defineProps<{ task: WorkOrder; draggable?: boolean }>();
const emit = defineEmits<{ open: []; toggle: [] }>();

const locale = computed(() => usePage<SharedProps>().props.locale);

const overdue = computed(() => isOverdue(props.task.due_date, props.task.status));
const done = computed(() => props.task.status === 'DONE');

/** "12 Aug – 14 Aug", or a single date when only one end is known. */
const dateRange = computed(() => {
    const format = (iso: string): string =>
        new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' });

    const { start_date: start, due_date: due } = props.task;

    if (start !== null && due !== null) return `${format(start)} – ${format(due)}`;
    if (due !== null) return format(due);
    if (start !== null) return `from ${format(start)}`;

    return null;
});

const priorityTone = computed(() => PRIORITY_TONES[props.task.priority]);
</script>

<template>
    <article
        :draggable="draggable"
        :class="
            cn(
                'flex cursor-pointer flex-col gap-2 border bg-card p-3 transition-colors',
                overdue ? 'border-board-overdue' : 'border-border hover:border-foreground/30',
            )
        "
        @click="emit('open')"
    >
        <div class="flex items-start gap-2">
            <!--
              The checkbox completes the task without opening it, which is the
              single most common thing anyone does on a board.
            -->
            <button
                type="button"
                class="mt-0.5 shrink-0"
                :aria-pressed="done"
                :aria-label="done ? `Reopen ${task.title}` : `Complete ${task.title}`"
                @click.stop="emit('toggle')"
            >
                <CircleCheck v-if="done" class="size-4 text-board-done" :stroke-width="2" />
                <span v-else class="block size-4 rounded-full border border-input" />
            </button>

            <h4
                class="min-w-0 flex-1 text-sm leading-5"
                :class="done && 'text-muted-foreground line-through'"
            >
                {{ task.title }}
            </h4>
        </div>

        <div v-if="task.category !== null || priorityTone" class="flex flex-wrap items-center gap-1.5 pl-6">
            <span
                v-if="task.category !== null"
                class="px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] uppercase"
                :class="categoryTone(task.category)"
            >
                {{ categoryLabel(task.category) }}
            </span>
            <span
                v-if="priorityTone"
                class="px-1.5 py-0.5 text-2xs font-medium tracking-[0.04em] uppercase"
                :class="priorityTone"
            >
                High
            </span>
        </div>

        <p
            v-if="dateRange"
            class="flex items-center gap-1.5 pl-6 text-xs"
            :class="overdue ? 'text-board-overdue' : 'text-muted-foreground'"
        >
            <CalendarDays class="size-3.5 shrink-0" :stroke-width="1.5" aria-hidden="true" />
            {{ dateRange }}<template v-if="overdue"> · Overdue</template>
        </p>

        <div class="flex items-center justify-between gap-2 pl-6">
            <span
                v-if="task.vessel || task.wine_lot"
                class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
            >
                <Container class="size-3.5 shrink-0" :stroke-width="1.5" aria-hidden="true" />
                <span class="truncate">{{ task.vessel?.name ?? task.wine_lot?.name }}</span>
            </span>
            <span v-else />

            <Avatar v-if="task.assignee" :name="task.assignee.name" size="sm" class="shrink-0" />
            <!-- An unassigned card says so, rather than leaving a hole where
                 every other card has a face. -->
            <span
                v-else
                class="grid size-6 shrink-0 place-items-center border border-dashed border-border text-2xs text-muted-foreground"
                :title="'Unassigned'"
                aria-label="Unassigned"
            >
                ?
            </span>
        </div>
    </article>
</template>
