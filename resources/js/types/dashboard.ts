/**
 * Mirrors App\Services\Dashboard\DashboardSummary::build().
 *
 * Every money field is an integer in MINOR units (cents); quantities that came
 * from decimal columns arrive as strings, never JS numbers, so precision
 * survives the wire. Keep both conventions when extending this file.
 */

export interface RevenuePoint {
    current: number;
    previous: number | null;
}

/** A point on the orders/revenue trend series. */
export interface SeriesPoint {
    label: string;
    value: number;
}

/** Order counts per status, keyed by the service's STATUS_KEY mapping. */
export interface OrderStatusCount {
    key: string;
    value: number;
}

export interface DashboardStats {
    total_orders: number;
    customers: number;
    /** Minor units. */
    revenue: number;
    low_stock: number;
    /** Minor units. */
    outstanding_ar: number;
    tasks_overdue: number;
}

export interface RecentOrderItem {
    name: string | null;
    quantity: string;
    unit_type: string | null;
}

export interface RecentOrder {
    id: string;
    order_number: string;
    customer: string;
    created_by: string | null;
    items: RecentOrderItem[];
    /** Minor units. */
    total: number;
    status: string;
    /** Pre-formatted short date, e.g. "Mar 4". */
    date: string;
}

export interface StockWatchItem {
    name: string;
    /** Decimal string. */
    stock: string;
    /** Decimal string. */
    min: string;
}

export interface TopProduct {
    name: string;
    value: number;
}

export interface DashboardSummary {
    range: string;
    currency: string;
    revenue_summary: Record<string, RevenuePoint>;
    revenue_by_channel: unknown;
    key_ratios: unknown;
    stats: DashboardStats;
    orders: SeriesPoint[];
    revenue: SeriesPoint[];
    order_status: OrderStatusCount[];
    top_products: TopProduct[];
    stock_watch: StockWatchItem[];
    recent_orders: RecentOrder[];
}

export interface DashboardFilters {
    period: string | null;
    range: string | null;
    from: string | null;
    to: string | null;
}
