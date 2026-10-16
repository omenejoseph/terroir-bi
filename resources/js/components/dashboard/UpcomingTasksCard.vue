<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { TicketCheck } from 'lucide-vue-next';

import Checkbox from '@/components/ui/Checkbox.vue';
import { useTranslations } from '@/composables/useTranslations';
import { categoryLabel } from '@/lib/work-orders';
import type { UpcomingTasks } from '@/types/dashboard';

/**
 * "Upcoming tasks" (Figma `286:745`): open work, soonest-due first, capped to
 * what the card has room for — App\Services\Dashboard\DashboardSummary
 * ::upcomingTasks() does the ranking; this only renders it.
 *
 * Ticking a task completes it on the spot (the row shows done at once, then the
 * refreshed summary drops it from the list), so routine work can be cleared
 * without leaving the dashboard.
 *
 * "View all" goes to the Work Orders board's own "Due soon" filter
 * (`due_soon=1`, Figma 267:1781) — the closest real filter to this card's
 * "this week" framing (due within 7 days, including overdue, vs. this
 * card's due-by-end-of-calendar-week), not an exact match invented to be one.
 */
defineProps<{ tasks: UpcomingTasks }>();

const { t } = useTranslations();

/** Ids ticked here, shown as done until the refreshed summary arrives. */
const completed = reactive(new Set<string>());

function complete(id: string): void {
    completed.add(id);

    router.patch(
        `/work-orders/${id}/status`,
        { status: 'DONE' },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['summary'],
            onError: () => completed.delete(id),
            onFinish: () => completed.delete(id),
        },
    );
}

/** "Overdue 3d" / "Due today" / "Due tomorrow" / "Due in 5d" / "No due date". */
function dueLabel(dueDate: string | null, overdue: boolean): string {
    if (dueDate === null) return t('No due date');

    const days = Math.round((new Date(dueDate).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 86_400_000);

    if (overdue) return t('Overdue :countd', { count: Math.abs(days) });
    if (days === 0) return t('Due today');
    if (days === 1) return t('Due tomorrow');

    return t('Due in :countd', { count: days });
}
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-1.5 text-sm font-semibold">
                <TicketCheck class="size-4 text-muted-foreground" :stroke-width="1.5" />
                {{ t('Upcoming tasks') }}
            </div>
            <Link href="/work-orders?due_soon=1" class="shrink-0 text-xs text-muted-foreground hover:text-foreground">
                {{ t('View all') }}
            </Link>
        </div>

        <div class="mt-3 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tabular-nums">{{ tasks.due_this_week }}</span>
            <span class="text-xs text-muted-foreground">{{ t('open this week') }}</span>
        </div>

        <ul v-if="tasks.rows.length" class="mt-4 flex-1 space-y-3">
            <li v-for="task in tasks.rows" :key="task.id" class="flex items-start gap-2.5">
                <Checkbox
                    :model-value="completed.has(task.id)"
                    :label="t('Mark complete')"
                    hide-label
                    class="mt-0.5"
                    @update:model-value="complete(task.id)"
                />
                <div class="min-w-0">
                    <p
                        class="truncate text-xs font-medium"
                        :class="completed.has(task.id) ? 'text-muted-foreground line-through' : 'text-foreground'"
                    >
                        {{ task.title }}
                    </p>
                    <p class="mt-0.5 flex items-center gap-1.5 text-2xs text-muted-foreground">
                        {{ categoryLabel(task.category) }}
                        <span
                            :class="task.overdue ? 'bg-destructive/10 px-1 py-px text-destructive' : 'text-muted-foreground'"
                        >
                            {{ dueLabel(task.due_date, task.overdue) }}
                        </span>
                    </p>
                </div>
            </li>
        </ul>
        <p v-else class="mt-4 flex-1 text-xs text-muted-foreground">{{ t('Nothing open.') }}</p>
    </div>
</template>
