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
    /** Which board this task is organized under — independent of category. */
    board_id: string | null;
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

/** One entry in the board picker — a real, user-created board. */
export interface BoardOption {
    key: string;
    label: string;
    count: number;
    /** At most one board is ever favourited per member. */
    favorite: boolean;
}

export interface WorkOrderFilters {
    search: string | null;
    category: string | null;
    /** The picker's selection — independent of category. */
    board_id: string | null;
    status: string | null;
    assignee_id: string | null;
    due_soon: boolean;
    mine: boolean;
    /** Rendered but inert — work orders have no recurrence. */
    recurring: boolean;
}
