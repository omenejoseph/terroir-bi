/** Mirrors App\DataTransferObjects\CustomerData and the Customers page props. */

import type { MoneyValue } from './inventory';

export interface PricingTierSummary {
    id: string;
    name: string;
    /** Decimal string — a percentage, not money. */
    rebate_percent: string;
}

export interface Customer {
    id: string;
    company_name: string;
    contact_name: string | null;
    email: string;
    phone: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    country: string | null;
    oib: string | null;
    /** App\Enums\CustomerType, e.g. 'WHOLESALE'. */
    customer_type: string | null;
    notes: string | null;
    is_active: boolean;
    /** What was typed on this customer; may be "0.00" while a tier supplies one. */
    rebate_percent: string;
    /** What actually applies — customer rebate wins, else the tier's. */
    effective_rebate_percent: string;
    hide_prices: boolean;
    is_agency: boolean | null;
    allow_single_bottle: boolean | null;
    exclude_from_stats: boolean | null;
    reorder_contacted_at: string | null;
    has_order_token: boolean;
    pricing_tier: PricingTierSummary | null;
    /** Loaded by ListCustomersQuery; null on a bare record. */
    order_count: number | null;
    /** Minor units, and null unless the viewer may see financials. */
    revenue_minor: number | null;
}

export interface CustomerFilters {
    search: string | null;
    is_active: boolean | null;
    pricing_tier_id: string | null;
    customer_type: string | null;
}

/** App\Queries\CustomerAnalyticsQuery — the Analytics tab. */
export interface CustomerAnalyticsRow {
    customer_id: string;
    company_name: string;
    contact_name: string | null;
    revenue_12m: MoneyValue;
    revenue_all_time: MoneyValue;
    order_count_12m: number;
    avg_order_value: MoneyValue;
    last_order_date: string | null;
    days_since_last_order: number | null;
    median_gap_days: number | null;
    expected_next_order_date: string | null;
}

export interface CustomerAnalytics {
    summary: {
        active_customers: number;
        revenue_12m: MoneyValue;
        top_customer: {
            id: string;
            company_name: string;
            contact_name: string | null;
            revenue_12m: MoneyValue;
        } | null;
    };
    customers: CustomerAnalyticsRow[];
}

/** App\Queries\CustomerInsightsQuery. */
export interface CustomerInsights {
    total_spend: MoneyValue;
    order_count: number;
    avg_order_value: MoneyValue;
    last_order_date: string | null;
    consignment_revenue: MoneyValue;
    top_products: {
        inventory_item_id: string;
        name: string | null;
        quantity: number;
        revenue: MoneyValue;
    }[];
}

/** App\Queries\CustomerOrderAnalyticsQuery. */
export interface CustomerOrderAnalytics {
    total_revenue: MoneyValue;
    this_year: MoneyValue;
    last_year: MoneyValue;
    last_order_date: string | null;
    /** Decimal string percentage. */
    yoy_growth_percent: string;
    annual_projection: MoneyValue;
    expected_next_order_date: string | null;
    next_quarter_projection: MoneyValue;
    expected_next_3m: { month: string; last_year: MoneyValue; expected: MoneyValue }[];
    monthly_revenue: { month: string; revenue: MoneyValue }[];
}

/** App\Queries\CustomerRhythmQuery — the order-rhythm strip. */
export interface CustomerRhythm {
    /** `position` is 0–1 across the plotted window. */
    orders: { date: string; position: number }[];
    from: string;
    to: string;
    median_gap_days: number | null;
    expected_next_date: string | null;
    expected_next_position: number | null;
    days_since_last: number | null;
    overdue: boolean;
}

/** App\Queries\CustomerProductsQuery — "Products bought". */
export interface CustomerProductRow {
    inventory_item_id: string;
    name: string;
    sku: string | null;
    vintage: number | null;
    unit_size: string | null;
    group: string | null;
    subcategory: string | null;
    units: number;
    /** 0–1 share of this customer's units. */
    share: number;
    revenue: MoneyValue;
    orders_with: number;
    last_ordered: string | null;
    /** Derived from order coverage; null when the data cannot support one. */
    signal: string | null;
}

export interface CustomerProducts {
    rows: CustomerProductRow[];
    total_units: number;
    product_count: number;
    order_count: number;
}

/** The Pricing tab: what this customer pays, and which rule decided it. */
export interface CustomerPriceRow {
    inventory_item_id: string;
    name: string;
    sku: string;
    vintage: number | null;
    unit_size: string | null;
    list_price: MoneyValue | null;
    price: MoneyValue | null;
    source: 'customer' | 'tier' | 'list' | 'none';
}

export interface CustomerPricing {
    rows: CustomerPriceRow[];
    override_count: number;
}

/** One card in the customer overview's "Needs attention" band. */
export interface AttentionCard {
    key: string;
    severity: 'critical' | 'warning';
    label: string;
    /** A formatted string, or money the client formats to the viewer's locale. */
    value: string | MoneyValue;
    detail: string;
    meta: string | MoneyValue;
    action: 'contact' | 'orders' | 'pricing';
}
