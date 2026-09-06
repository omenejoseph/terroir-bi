/**
 * Mirrors App\Services\Dashboard\DashboardSummary::build().
 *
 * Every money field is an integer in MINOR units (cents); quantities that came
 * from decimal columns arrive as strings, never JS numbers, so precision
 * survives the wire. Keep both conventions when extending this file.
 */

import type { MoneyValue } from '@/types/inventory';

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
    /** Current state, not scoped to the selected period — see stats.low_stock. */
    ready_to_ship: number;
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
    /** 'bottles' | 'cases' — App\Enums\SalesUnit. */
    unit: string;
}

export interface TopProduct {
    name: string;
    value: number;
}

/**
 * The bottom ratio grid (Figma `208:6303`). Every field is nullable: a ratio
 * with no reliable denominator (no payroll imported, no shipped orders yet)
 * renders "—" rather than a misleading 0%.
 */
export interface DashboardKeyRatios {
    dtc_revenue_pct: number | null;
    operating_margin_pct: number | null;
    employee_cost_pct: number | null;
    marketing_cost_pct: number | null;
    cogs_pct: number | null;
    cogs_amount: MoneyValue | null;
    revenue_per_employee: MoneyValue | null;
    avg_order_value: MoneyValue | null;
    inventory_turnover: number | null;
}

export interface ReorderPipelineRow {
    customer_id: string;
    company_name: string;
    days_since_last: number;
    avg_order_value: MoneyValue;
}

export interface ReorderPipeline {
    /** Combined avg order value across every flagged account, not just the rows shown. */
    total: MoneyValue;
    rows: ReorderPipelineRow[];
}

export interface UpcomingTask {
    id: string;
    title: string;
    /** null for uncategorised work — App\Enums\WorkOrderCategory otherwise. */
    category: string | null;
    due_date: string | null;
    overdue: boolean;
}

export interface UpcomingTasks {
    /** Open work due by the end of this week, including anything already overdue. */
    due_this_week: number;
    rows: UpcomingTask[];
}

export interface CashCategorySplit {
    label: string;
    amount: MoneyValue;
    percent: number;
}

export interface NetCashFlow {
    /** Minor units; negative when the period spent more than it collected. */
    net: MoneyValue;
    by_category: CashCategorySplit[];
}

/** One channel's line in "Target by channel" — only present when that channel has a target set. */
export interface RevenueTargetChannel {
    key: string;
    label: string;
    target: MoneyValue;
    current: MoneyValue;
    /** Current YTD revenue as a % of the target prorated to today's point in the year; null if the target is 0. */
    pace_pct: number | null;
}

/**
 * "Revenue vs. target" (Figma 208:5577 / 286:781). `annual_target` and any
 * `channels` entry are absent until a settings.manage member sets a real
 * figure on the Settings page — see App\Services\Dashboard\DashboardSummary::revenueVsTarget().
 */
export interface RevenueVsTarget {
    annual_target: MoneyValue | null;
    ytd_revenue: MoneyValue;
    /** ytd_revenue as a % of annual_target; null if no target is set. */
    progress_pct: number | null;
    channels: RevenueTargetChannel[];
}

/**
 * "Runway" (Figma 208:5808). Null entirely until a settings.manage member
 * sets a cash-on-hand figure on the Settings page — see
 * App\Services\Dashboard\DashboardSummary::runway().
 */
export interface Runway {
    cash_on_hand: MoneyValue;
    cash_on_hand_as_of: string | null;
    /** Trailing-3-month average burn; null when the trailing quarter wasn't a burn (net positive). */
    monthly_burn: MoneyValue | null;
    /** cash_on_hand / monthly_burn; null when monthly_burn is null. */
    months: number | null;
}

export interface DashboardSummary {
    range: string;
    currency: string;
    revenue_summary: Record<string, RevenuePoint>;
    revenue_by_channel: Record<string, RevenuePoint>;
    /** Trailing 6 calendar months, independent of the selected period. */
    revenue_trend: SeriesPoint[];
    key_ratios: DashboardKeyRatios;
    stats: DashboardStats;
    orders: SeriesPoint[];
    revenue: SeriesPoint[];
    order_status: OrderStatusCount[];
    top_products: TopProduct[];
    stock_watch: StockWatchItem[];
    recent_orders: RecentOrder[];
    reorder_pipeline: ReorderPipeline;
    upcoming_tasks: UpcomingTasks;
    net_cash_flow: NetCashFlow;
    revenue_vs_target: RevenueVsTarget;
    runway: Runway | null;
}

export interface DashboardFilters {
    period: string | null;
    range: string | null;
    from: string | null;
    to: string | null;
}
