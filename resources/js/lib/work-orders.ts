/**
 * Work-order vocabulary and the board's colour encoding.
 *
 * Work Orders is the only screen in the design that uses colour, and it uses it
 * to say two things at a glance: what KIND of work a card is, and how urgently
 * it is wanted. The tones are sampled from the render of `267:1781` and live as
 * `--tag-*` tokens so they theme with everything else.
 */

import type { TaskPriorityKey, WorkOrderCategoryKey } from '@/types/work-orders';

export const CATEGORY_LABELS: Record<WorkOrderCategoryKey, string> = {
    CELLAR: 'Cellar',
    VINEYARD: 'Vineyard',
    MAINTENANCE: 'Maintenance',
    ADMIN: 'Admin',
    DELIVERY: 'Delivery',
    EVENT: 'Events',
    OTHER: 'Other',
};

/**
 * A tone per category. The design only shows CELLAR (violet); the rest are
 * assigned from the same token set so every category is distinguishable rather
 * than six of them sharing one grey.
 */
export const CATEGORY_TONES: Record<WorkOrderCategoryKey, string> = {
    CELLAR: 'bg-tag-violet text-tag-violet-foreground',
    VINEYARD: 'bg-tag-green text-tag-green-foreground',
    MAINTENANCE: 'bg-tag-slate text-tag-slate-foreground',
    ADMIN: 'bg-tag-blue text-tag-blue-foreground',
    DELIVERY: 'bg-tag-orange text-tag-orange-foreground',
    EVENT: 'bg-tag-red text-tag-red-foreground',
    OTHER: 'bg-muted text-muted-foreground',
};

export const PRIORITY_LABELS: Record<TaskPriorityKey, string> = {
    LOW: 'Low',
    MEDIUM: 'Medium',
    HIGH: 'High',
};

/**
 * Only HIGH gets a badge. The design shows HIGH and URGENT, but this domain's
 * `TaskPriority` stops at High — badging MEDIUM and LOW too would put a chip on
 * every card and stop the badge meaning anything.
 */
export const PRIORITY_TONES: Partial<Record<TaskPriorityKey, string>> = {
    HIGH: 'bg-tag-orange text-tag-orange-foreground',
};

export const STATUS_LABELS: Record<string, string> = {
    TODO: 'To Do',
    IN_PROGRESS: 'In Progress',
    DONE: 'Done',
};

export function categoryLabel(value: string | null): string {
    if (value === null) return 'Uncategorised';

    return CATEGORY_LABELS[value as WorkOrderCategoryKey] ?? value;
}

export function categoryTone(value: string | null): string {
    if (value === null) return 'bg-muted text-muted-foreground';

    return CATEGORY_TONES[value as WorkOrderCategoryKey] ?? 'bg-muted text-muted-foreground';
}

/** True when work is still open and its due date has passed. */
export function isOverdue(dueDate: string | null, status: string): boolean {
    if (dueDate === null || status === 'DONE') return false;

    return new Date(dueDate).getTime() < Date.now();
}
