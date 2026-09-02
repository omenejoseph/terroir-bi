/**
 * Mirrors App\Queries\InventoryItemStockAnalyticsQuery::get() and
 * App\Queries\ItemMovementsQuery::get().
 *
 * Money is `MoneyValue` (integer minor units). Quantities are decimal strings.
 */

import type { MoneyValue } from '@/types/inventory';

export interface StockCurrent {
    stock_bottles: number;
    unit: string;
    bottles_per_case: number;
    min_stock_bottles: number;
    cost_per_bottle: MoneyValue | null;
    selling_per_bottle: MoneyValue | null;
}

/** Trailing-12-month realised figures; all null until something has sold. */
export interface StockRealized {
    mean_price: MoneyValue | null;
    rebate_percent: string | null;
    rebate_amount: MoneyValue | null;
    margin_percent: string | null;
    margin_amount: MoneyValue | null;
    sales_value: MoneyValue;
    bottles_sold: number;
}

export interface StockExits {
    bottles_exited: number;
    movements_count: number;
    /** Daily bottles-exited buckets across the window, for the bar chart. */
    spark: number[];
    cost_of_exits: MoneyValue | null;
    revenue_realized: MoneyValue | null;
    mean_margin_percent: string | null;
    velocity_per_day: string;
    days_of_stock_left: number | null;
    internal: { bottles: number; cost: MoneyValue; revenue: MoneyValue } | null;
}

export interface StockChannel {
    channel: string;
    bottles: number;
}

export interface StockAnalytics {
    period: string;
    current: StockCurrent;
    realized: StockRealized;
    exits: StockExits;
    channels: StockChannel[];
}

export interface StockMovement {
    id: string;
    type: string;
    quantity: string;
    unit: string | null;
    reference: string | null;
    note: string | null;
    is_reconciliation: boolean;
    created_at: string | null;
    created_by: { id: string; name: string } | null;
    /** Running stock after this movement, derived by ItemMovementsQuery. */
    balance: string;
}

/** Mirrors App\Queries\InventoryAnalyticsQuery::get(). */
export interface InventoryAnalyticsSummary {
    total_active: number;
    low_stock: number;
    out_of_stock: number;
    for_sale: number;
    priced_count: number;
    finished_units: number;
    finished_products: number;
    costed_count: number;
    sale_value: MoneyValue;
    production_value: MoneyValue;
    margin_percent: string | null;
    by_category: Record<string, number> | { category: string; count: number }[];
}

export interface PortfolioChannel {
    key: string;
    units: number;
    revenue: MoneyValue | null;
    margin_percent: string | null;
    share_percent: string;
}

export interface PortfolioExits {
    period_days: number;
    external: {
        units_exited: number;
        cost_of_exits: MoneyValue | null;
        revenue_realized: MoneyValue | null;
        mean_margin_percent: string | null;
    };
    channels: PortfolioChannel[];
}

export interface InventoryAnalytics {
    summary: InventoryAnalyticsSummary;
    portfolio_exits: PortfolioExits;
    movements_12m: { month: string; in: number; out: number }[];
    top_products: { name: string; value: number }[];
    by_group: { group: string | null; count: number }[];
    stock_levels: { name: string; stock: string }[];
    value: { total: number; currency: string; categories: { category: string; value: number }[] };
    low_stock: {
        below: { name: string; stock: string; min: string }[];
        approaching: { name: string; stock: string; min: string }[];
    };
}

/** Mirrors App\Queries\InventorySpendQuery::get(). */
export interface SpendSummary {
    units_exited: number;
    movements: number;
    cost_value: MoneyValue;
    revenue: MoneyValue;
    distinct_skus: number;
}

export interface SpendProduct {
    id: string;
    name: string;
    sku: string;
    vintage: string | null;
    group: string | null;
    subcategory: string | null;
    on_hand: number;
    units_exited: number;
    prev_units_exited: number;
    velocity_per_day: string;
    days_left: number | null;
    cost_of_exits: MoneyValue | null;
    revenue: MoneyValue | null;
    daily?: { date: string; units: number }[];
}

export interface InventorySpend {
    period: { from: string; to: string; days: number };
    previous_period: { from: string; to: string; days: number };
    summary: SpendSummary;
    previous: SpendSummary;
    daily: { date: string; units: number }[];
    per_product: SpendProduct[];
}
