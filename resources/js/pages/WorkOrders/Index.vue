<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Plus, Search, Star } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BoardColumnView from '@/components/work-orders/BoardColumn.vue';
import TaskPanel from '@/components/work-orders/TaskPanel.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { usePopover } from '@/composables/usePopover';
import { cn } from '@/lib/cn';
import { formatNumber } from '@/lib/money';
import type { Board, BoardOption, TaskStatusKey, WorkOrder, WorkOrderFilters } from '@/types/work-orders';
import type { SharedProps } from '@/types';

/**
 * Work Orders (Figma 267:1781): a board of task cards in status columns, with
 * a board picker above and three quick filters beside the search.
 *
 * One structural note, explained in App\Services\Tasks\WorkOrderBoardPresenter:
 * the design's four lists include "This Week", which is a date window rather
 * than a status — dragging a card into it would have to mean "reschedule", so
 * the columns are the three real statuses and "Due soon" answers the same
 * question as a filter.
 */
const props = defineProps<{
    board: Board;
    boards: BoardOption[];
    filters: WorkOrderFilters;
}>();

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const search = ref(props.filters.search ?? '');
const panelOpen = ref(false);
const editing = ref<WorkOrder | null>(null);
/** The column a new card should land in, when it was started from a column. */
const newInStatus = ref<TaskStatusKey | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ search: value || undefined }), 300);
});

function reload(overrides: Record<string, unknown>): void {
    router.get(
        '/work-orders',
        {
            search: props.filters.search ?? undefined,
            category: props.filters.category ?? undefined,
            board_id: props.filters.board_id ?? undefined,
            due_soon: props.filters.due_soon ? 1 : undefined,
            mine: props.filters.mine ? 1 : undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['board', 'boards', 'filters'] },
    );
}

function selectBoard(key: string | null): void {
    reload({ board_id: props.filters.board_id === key ? undefined : (key ?? undefined) });
}

function toggleFilter(key: 'due_soon' | 'mine'): void {
    reload({ [key]: props.filters[key] ? undefined : 1 });
}

/* ---- boards: favourite and create ------------------------------------ */

/** At most one board is ever favourited — the header's jump-to-it button. */
const favoriteBoard = computed(() => props.boards.find((b) => b.favorite) ?? null);

function toggleFavorite(option: BoardOption): void {
    router.patch(
        '/work-order-boards/favorite',
        { board_id: option.favorite ? null : option.key },
        { preserveScroll: true, preserveState: true },
    );
}

const newBoardAnchor = ref<HTMLElement | null>(null);
const newBoardPopover = usePopover(newBoardAnchor);
const newBoardName = ref('');

function submitNewBoard(): void {
    const name = newBoardName.value.trim();

    if (name === '') return;

    router.post(
        '/work-order-boards',
        { name },
        {
            preserveScroll: true,
            onSuccess: () => {
                newBoardName.value = '';
                newBoardPopover.close();
            },
        },
    );
}

/* ---- drag and drop --------------------------------------------------- */

const dragging = ref<WorkOrder | null>(null);
const draggingId = computed(() => dragging.value?.id ?? null);

/**
 * A drop is up to two writes, and they are different things: crossing columns
 * changes STATUS (which carries the completion side effects), and the position
 * within a column is SORT ORDER. Doing both in one endpoint would conflate
 * "this work has moved on" with "I want to see it higher up".
 */
function onDrop(payload: { status: TaskStatusKey; beforeId: string | null }): void {
    const task = dragging.value;
    dragging.value = null;

    if (task === null) return;

    const movedColumn = task.status !== payload.status;
    const ids = orderedIdsAfterDrop(task, payload);

    if (movedColumn) {
        router.patch(
            `/work-orders/${task.id}/status`,
            { status: payload.status },
            {
                preserveScroll: true,
                onSuccess: () => persistOrder(ids),
            },
        );

        return;
    }

    persistOrder(ids);
}

function persistOrder(ids: string[]): void {
    if (ids.length === 0) return;

    router.post('/work-orders/reorder', { ids }, { preserveScroll: true });
}

/** The target column's ids with the dragged card spliced into its new place. */
function orderedIdsAfterDrop(task: WorkOrder, payload: { status: TaskStatusKey; beforeId: string | null }): string[] {
    const column = props.board.columns.find((c) => c.key === payload.status);

    if (column === undefined) return [];

    const ids = column.tasks.map((t) => t.id).filter((id) => id !== task.id);
    const at = payload.beforeId === null ? ids.length : ids.indexOf(payload.beforeId);

    ids.splice(at < 0 ? ids.length : at, 0, task.id);

    return ids;
}

/* ---- card actions ---------------------------------------------------- */

function toggleDone(task: WorkOrder): void {
    router.patch(
        `/work-orders/${task.id}/status`,
        { status: task.status === 'DONE' ? 'TODO' : 'DONE' },
        { preserveScroll: true },
    );
}

function open(task: WorkOrder): void {
    editing.value = task;
    newInStatus.value = null;
    panelOpen.value = true;
}

function create(status: TaskStatusKey | null = null): void {
    editing.value = null;
    newInStatus.value = status;
    panelOpen.value = true;
}

const summary = computed(
    () =>
        `${props.boards.length} ${props.boards.length === 1 ? 'board' : 'boards'} · ` +
        `${formatNumber(props.board.total, locale.value)} tasks`,
);
</script>

<template>
    <AppLayout title="Work Orders">
        <div class="space-y-4">
            <PageHeader title="Work Orders" :description="summary">
                <template #actions>
                    <!-- @todo Recent Activity. There is no activity log for work
                         orders — status changes are not journalled the way order
                         status is. -->
                    <Button variant="outline" size="sm">Recent Activity</Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="favoriteBoard === null"
                        @click="favoriteBoard !== null && selectBoard(favoriteBoard.key)"
                    >
                        <Star class="size-3.5 text-board-star" :stroke-width="1.5" fill="currentColor" />
                        Favorited
                    </Button>
                    <div ref="newBoardAnchor" class="relative">
                        <Button variant="outline" size="sm" :aria-expanded="newBoardPopover.open.value" aria-haspopup="dialog" @click="newBoardPopover.toggle()">
                            <Plus class="size-3.5" :stroke-width="1.5" />
                            New Board
                        </Button>

                        <form
                            v-if="newBoardPopover.open.value"
                            role="dialog"
                            aria-label="New board"
                            class="absolute top-9 right-0 z-30 flex w-56 items-center gap-2 border border-border bg-card p-2 shadow-lg"
                            @submit.prevent="submitNewBoard"
                        >
                            <input
                                v-model="newBoardName"
                                type="text"
                                placeholder="Board name"
                                aria-label="Board name"
                                autofocus
                                class="h-8 w-full min-w-0 flex-1 border border-input bg-card px-2 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />
                            <Button type="submit" size="sm" :disabled="newBoardName.trim() === ''">Add</Button>
                        </form>
                    </div>
                    <Button size="sm" @click="create()">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        New Task
                    </Button>
                </template>
            </PageHeader>

            <!-- Board picker -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :aria-pressed="filters.board_id === null"
                    :class="
                        cn(
                            'inline-flex h-8 items-center gap-2 px-3 text-xs transition-colors',
                            filters.board_id === null
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-foreground hover:bg-muted/70',
                        )
                    "
                    @click="selectBoard(null)"
                >
                    All work
                    <span class="tabular-nums opacity-60">{{ board.total }}</span>
                </button>

                <span
                    v-for="option in boards"
                    :key="option.key"
                    :class="
                        cn(
                            'inline-flex h-8 items-center gap-0.5 pl-3 text-xs transition-colors',
                            filters.board_id === option.key
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-foreground hover:bg-muted/70',
                        )
                    "
                >
                    <button
                        type="button"
                        :aria-pressed="filters.board_id === option.key"
                        class="inline-flex items-center gap-2"
                        @click="selectBoard(option.key)"
                    >
                        {{ option.label }}
                        <span class="tabular-nums opacity-60">{{ option.count }}</span>
                    </button>
                    <button
                        type="button"
                        :aria-pressed="option.favorite"
                        :aria-label="option.favorite ? `Unfavorite ${option.label}` : `Favorite ${option.label}`"
                        class="p-1.5"
                        @click.stop="toggleFavorite(option)"
                    >
                        <Star
                            class="size-3 text-board-star"
                            :stroke-width="1.5"
                            :fill="option.favorite ? 'currentColor' : 'none'"
                        />
                    </button>
                </span>
            </div>

            <!-- Filter row -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full max-w-[280px]">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                        :stroke-width="1.5"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search work orders…"
                        aria-label="Search work orders"
                        class="h-8 w-full border border-input bg-card pr-3 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <button
                    type="button"
                    :aria-pressed="filters.due_soon"
                    :class="
                        cn(
                            'inline-flex h-8 items-center border px-2.5 text-xs transition-colors',
                            filters.due_soon
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:border-foreground/40',
                        )
                    "
                    @click="toggleFilter('due_soon')"
                >
                    Due soon
                </button>

                <button
                    type="button"
                    :aria-pressed="filters.mine"
                    :class="
                        cn(
                            'inline-flex h-8 items-center border px-2.5 text-xs transition-colors',
                            filters.mine
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:border-foreground/40',
                        )
                    "
                    @click="toggleFilter('mine')"
                >
                    My Tasks
                </button>

                <!-- @todo Recurring. A work order has no recurrence field, so
                     this cannot filter anything yet. -->
                <button
                    type="button"
                    class="inline-flex h-8 items-center border border-border bg-card px-2.5 text-xs hover:border-foreground/40"
                >
                    Recurring
                </button>
            </div>

            <!-- The board scrolls sideways inside its own container. -->
            <div class="flex items-start gap-3 overflow-x-auto pb-4">
                <BoardColumnView
                    v-for="column in board.columns"
                    :key="column.key"
                    :column="column"
                    :dragging-id="draggingId"
                    @open="open"
                    @toggle="toggleDone"
                    @add="create(column.key)"
                    @drag-start="dragging = $event"
                    @drag-end="dragging = null"
                    @drop="onDrop"
                />

                <!-- @todo Add another list. Columns are the TaskStatus enum, so
                     a new one would be a new status, not a user-made list. -->
                <button
                    type="button"
                    class="flex h-10 w-56 shrink-0 items-center gap-2 border border-dashed border-border px-3 text-xs text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground"
                >
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    Add another list
                </button>
            </div>

            <p v-if="board.total === 0" class="border border-border bg-card px-4 py-12 text-center text-xs text-muted-foreground">
                {{
                    filters.search || filters.category || filters.board_id || filters.due_soon || filters.mine
                        ? 'No work matches these filters.'
                        : 'No work orders yet. Start one with New Task.'
                }}
            </p>
        </div>

        <TaskPanel
            :open="panelOpen"
            :task="editing"
            :default-status="newInStatus"
            :default-board-id="filters.board_id"
            @close="panelOpen = false"
        />
    </AppLayout>
</template>
