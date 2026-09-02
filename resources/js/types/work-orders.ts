/** Mirrors App\DataTransferObjects\WorkOrderData and the board's page props. */

/** Mirrors App\Enums\TaskStatus — the board's three columns. */
export type TaskStatusKey = 'TODO' | 'IN_PROGRESS' | 'DONE';

/** Mirrors App\Enums\TaskPriority. */
export type TaskPriorityKey = 'LOW' | 'MEDIUM' | 'HIGH';

/** Mirrors App\Enums\WorkOrderCategory. */
export type WorkOrderCategoryKey =
    | 'CELLAR'
    | 'VINEYARD'
    | 'MAINTENANCE'
    | 'ADMIN'
    | 'DELIVERY'
    | 'EVENT'
    | 'OTHER';

export interface NamedRef {
    id: string;
    name: string;
}

export interface WorkOrder {
    id: string;
    title: string;
    description: string | null;
    category: WorkOrderCategoryKey | null;
    priority: TaskPriorityKey;
    status: TaskStatusKey;
    start_date: string | null;
    due_date: string | null;
    completed_at: string | null;
    sort_order: number;
    assignee: NamedRef | null;
    /** Where the work happens — the card's "Tank A-2" line. */
    vessel: NamedRef | null;
    wine_lot: NamedRef | null;
    created_by: NamedRef | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface BoardColumn {
    key: TaskStatusKey;
    label: string;
    count: number;
    tasks: WorkOrder[];
}

export interface Board {
    columns: BoardColumn[];
    total: number;
}

/**
 * One entry in the board picker. Built from the categories that have work —
 * this domain has no board entity; see App\Services\Tasks\WorkOrderBoard.
 */
export interface BoardOption {
    key: string;
    label: string;
    count: number;
}

export interface WorkOrderFilters {
    search: string | null;
    category: string | null;
    status: string | null;
    assignee_id: string | null;
    due_soon: boolean;
    mine: boolean;
    /** Rendered but inert — work orders have no recurrence. */
    recurring: boolean;
}
