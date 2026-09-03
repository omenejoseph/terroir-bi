<script setup lang="ts">
import { TicketCheck } from 'lucide-vue-next';

import Checkbox from '@/components/ui/Checkbox.vue';
import { categoryLabel } from '@/lib/work-orders';
import type { UpcomingTasks } from '@/types/dashboard';

/**
 * "Upcoming tasks" (Figma `286:745`): open work, soonest-due first, capped to
 * what the card has room for — App\Services\Dashboard\DashboardSummary
 * ::upcomingTasks() does the ranking; this only renders it.
 *
 * The checkbox is decorative (`disabled`), matching the card's own note
 * (`408:1577`): "Tasks card now looks like the three cards next to it" is
 * about the card's shape, not about ticking work off from the dashboard —
 * that stays the Work Orders board's job.
 *
 * @todo "View all" would go to /work-orders; not wired because there is no
 * "due this week" filter there yet for it to land on meaningfully.
 */
defineProps<{ tasks: UpcomingTasks }>();

/** "Overdue 3d" / "Due today" / "Due tomorrow" / "Due in 5d" / "No due date". */
function dueLabel(dueDate: string | null, overdue: boolean): string {
    if (dueDate === null) return 'No due date';

    const days = Math.round((new Date(dueDate).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 86_400_000);

    if (overdue) return `Overdue ${Math.abs(days)}d`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';

    return `Due in ${days}d`;
}
</script>

<template>
    <div class="flex h-full flex-col border border-border bg-card p-4">
        <div class="flex items-center gap-1.5 text-sm font-semibold">
            <TicketCheck class="size-4 text-muted-foreground" :stroke-width="1.5" />
            Upcoming tasks
        </div>

        <div class="mt-3 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tabular-nums">{{ tasks.due_this_week }}</span>
            <span class="text-xs text-muted-foreground">open this week</span>
        </div>

        <ul v-if="tasks.rows.length" class="mt-4 flex-1 space-y-3">
            <li v-for="task in tasks.rows" :key="task.id" class="flex items-start gap-2.5">
                <Checkbox :model-value="false" label="Mark complete" hide-label disabled class="mt-0.5" />
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-foreground">{{ task.title }}</p>
                    <p class="text-2xs text-muted-foreground">
                        {{ categoryLabel(task.category) }} ·
                        <span :class="task.overdue && 'text-destructive'">{{ dueLabel(task.due_date, task.overdue) }}</span>
                    </p>
                </div>
            </li>
        </ul>
        <p v-else class="mt-4 flex-1 text-xs text-muted-foreground">Nothing open.</p>
    </div>
</template>
