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
