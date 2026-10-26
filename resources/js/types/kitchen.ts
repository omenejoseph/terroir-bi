import type { MoneyValue } from './inventory';

export interface KitchenOption {
    value: string;
    label: string;
}

export interface KitchenOptions {
    categories: KitchenOption[];
    locations: KitchenOption[];
    units: KitchenOption[];
    work_order_statuses: KitchenOption[];
}

/** KitchenPresenter::item — quantities are decimal strings ("12.500"). */
export interface KitchenItem {
    id: string;
    name: string;
    category: string;
    group: string | null;
    unit: string;
    current_stock: string;
    min_stock: string | null;
    cost_per_unit: MoneyValue | null;
    supplier: string | null;
    location: string;
    is_active: boolean;
    is_low: boolean;
}

export interface KitchenMovement {
    id: string;
    type: 'IN' | 'OUT' | 'ADJUSTMENT';
    /** The signed change. */
    quantity: string;
    note: string | null;
    by: string | null;
    at: string | null;
}

export interface KitchenCheckSummary {
    id: string;
    date: string;
    status: 'IN_PROGRESS' | 'COMPLETED';
    location: string | null;
    note: string | null;
    completed_at: string | null;
    by: string | null;
    lines: number;
    counted: number;
}

export interface KitchenCheckLine {
    id: string;
    item_id: string;
    name: string | null;
    category: string | null;
    unit: string | null;
    system_stock: string;
    physical_count: string | null;
    difference: string | null;
}

export interface KitchenCheckDetail extends KitchenCheckSummary {
    items: KitchenCheckLine[];
}

export interface KitchenWorkOrderLine {
    id: string;
    item_id: string | null;
    name: string | null;
    custom_name: string | null;
    quantity: string;
    unit: string | null;
    notes: string | null;
}

export interface KitchenWorkOrder {
    id: string;
    title: string;
    date: string;
    status: 'PLANNED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
    assigned_to: string | null;
    notes: string | null;
    completed_at: string | null;
    by: string | null;
    items: KitchenWorkOrderLine[];
}

export interface KitchenWorkOrderFilters {
    status: string | null;
    assigned_to: string | null;
    from: string | null;
    to: string | null;
}
