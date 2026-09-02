<script setup lang="ts">
import { ref } from 'vue';
import { MoreHorizontal, Plus } from 'lucide-vue-next';

import TaskCard from '@/components/work-orders/TaskCard.vue';
import GripHandle from '@/components/ui/GripHandle.vue';
import { cn } from '@/lib/cn';
import type { BoardColumn, WorkOrder } from '@/types/work-orders';

/**
 * One list on the board (Figma 267:1781): a header carrying the column's name
 * and count, its cards, and an "Add a card" affordance at the foot.
 *
 * Drag and drop is plain HTML5 — no library. A board needs exactly two moves,
 * "into this column" and "before that card", and both are a `dragover` and a
 * `drop` away; pulling in a drag library for that would cost more bundle than
 * the whole page.
 */
const props = defineProps<{ column: BoardColumn; draggingId: string | null }>();

const emit = defineEmits<{
    open: [task: WorkOrder];
    toggle: [task: WorkOrder];
    add: [];
    dragStart: [task: WorkOrder];
    dragEnd: [];
    /** Dropped on this column, before `beforeId` (or at the end when null). */
    drop: [payload: { status: BoardColumn['key']; beforeId: string | null }];
}>();

/** Only the column actually under the pointer highlights. */
const over = ref(false);
/** Which card the drop would land before, so the gap appears in the right place. */
const beforeId = ref<string | null>(null);

function onDragOver(event: DragEvent): void {
    if (props.draggingId === null) return;

    // Without preventDefault the browser refuses the drop outright.
    event.preventDefault();
    over.value = true;
}

function onCardDragOver(event: DragEvent, task: WorkOrder): void {
    if (props.draggingId === null || task.id === props.draggingId) return;

    event.preventDefault();
    event.stopPropagation();
    over.value = true;

    // Above the midpoint means "before this card", below means "after it".
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    beforeId.value = event.clientY < box.top + box.height / 2 ? task.id : nextIdAfter(task.id);
}

function nextIdAfter(id: string): string | null {
    const index = props.column.tasks.findIndex((t) => t.id === id);

    return props.column.tasks[index + 1]?.id ?? null;
}

function onDrop(): void {
    if (props.draggingId === null) return;

    emit('drop', { status: props.column.key, beforeId: beforeId.value });
    reset();
}

function reset(): void {
    over.value = false;
    beforeId.value = null;
}
</script>

<template>
    <section
        :class="
            cn(
                'flex w-72 shrink-0 flex-col gap-2 border p-2 transition-colors',
                over ? 'border-foreground/40 bg-muted' : 'border-transparent bg-muted/40',
            )
        "
        :aria-label="column.label"
        @dragover="onDragOver"
        @dragleave="reset"
        @drop="onDrop"
    >
        <header class="flex items-center gap-2 px-1 py-1">
            <GripHandle class="shrink-0" />
            <h3 class="min-w-0 flex-1 truncate text-sm font-semibold">{{ column.label }}</h3>
            <span class="text-xs text-muted-foreground tabular-nums">{{ column.count }}</span>
            <!-- @todo Column menu. The design offers per-list actions (rename,
                 archive, sort); lists here are the TaskStatus enum, so there is
                 nothing yet for those actions to change. -->
            <button
                type="button"
                class="grid size-7 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                :aria-label="`${column.label} actions`"
            >
                <MoreHorizontal class="size-4" :stroke-width="1.5" />
            </button>
        </header>

        <div class="flex flex-col gap-2">
            <template v-for="task in column.tasks" :key="task.id">
                <!-- The gap the card would drop into. -->
                <div v-if="beforeId === task.id" class="h-1 bg-foreground/30" aria-hidden="true" />
                <div
                    :class="draggingId === task.id && 'opacity-40'"
                    @dragover="onCardDragOver($event, task)"
                >
                    <TaskCard
                        :task="task"
                        draggable
                        @open="emit('open', task)"
                        @toggle="emit('toggle', task)"
                        @dragstart="emit('dragStart', task)"
                        @dragend="((emit('dragEnd')), reset())"
                    />
                </div>
            </template>

            <div v-if="over && beforeId === null && draggingId !== null" class="h-1 bg-foreground/30" aria-hidden="true" />
        </div>

        <button
            type="button"
            class="flex items-center gap-2 px-1 py-2 text-xs text-muted-foreground transition-colors hover:text-foreground"
            @click="emit('add')"
        >
            <Plus class="size-3.5" :stroke-width="1.5" />
            Add a card
        </button>
    </section>
</template>
