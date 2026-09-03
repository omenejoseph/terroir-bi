/** Mirrors App\DataTransferObjects\OrderData and the Orders page props. */

import type { MoneyValue } from './inventory';

/** Mirrors App\Enums\OrderStatus. The four statuses this domain actually has. */
export type OrderStatusKey = 'RECEIVED' | 'IN_PROCESS' | 'READY_TO_SHIP' | 'SHIPPED';

export interface OrderUser {
    id: string;
    name: string;
}

export interface OrderCustomer {
    id: string;
    company_name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    /** App\Enums\CustomerType, e.g. 'WHOLESALE'. */
    customer_type: string | null;
    /** Decimal string — a percentage, not money. */
    rebate_percent: string;
}

export interface OrderLine {
    id: string;
    inventory_item_id: string | null;
    name: string;
    sku: string | null;
    group: string | null;
    unit_size: string | null;
    bottles_per_case: number | null;
    image_url: string | null;
    quantity: number;
    /** App\Enums\SalesUnit: 'bottles' | 'cases'. */
    unit_type: string;
    unit_price: MoneyValue;
    total: MoneyValue;
    custom_description: string | null;
    /** Financials only. */
    cost_per_unit?: MoneyValue | null;
    profit?: MoneyValue | null;
}

export interface OrderStatusEvent {
    status: OrderStatusKey;
    note: string | null;
    changed_by: OrderUser | null;
    created_at: string | null;
}

export interface OrderComment {
    id: string;
    content: string;
    author: OrderUser | null;
    created_at: string | null;
}

export interface OrderProfitability {
    revenue: MoneyValue;
    cogs: MoneyValue;
    logistics: MoneyValue | null;
    gross_profit: MoneyValue;
    /** Decimal string percentage. */
    margin_percent: string;
    complete: boolean;
    missing_cost_items: string[];
}

export interface OrderPayment {
    amount_paid: MoneyValue;
    balance_due: MoneyValue;
    status: 'UNPAID' | 'PARTIAL' | 'PAID';
}

export interface Order {
    id: string;
    order_number: string;
    status: OrderStatusKey;
    total_amount: MoneyValue;
    notes: string | null;
    customer: OrderCustomer | null;
    created_by: OrderUser | null;
    is_backorder: boolean;
    backorder_date: string | null;
    deduct_stock: boolean;
    shipping_cost: MoneyValue | null;
    shipping_paid_by_us: boolean;
    is_consignment: boolean;
    consignment_closed_at: string | null;
    /** Only present when the viewer may see finance. */
    payment: OrderPayment | null;
    profitability: OrderProfitability | null;
    created_at: string | null;
    items: OrderLine[];
    status_history: OrderStatusEvent[];
    comments: OrderComment[];
}

/** One column of the order-to-cash pipeline card (App\Queries\OrderPipelineQuery). */
export interface PipelineStage {
    key: string;
    label: string;
    count: number;
    value: MoneyValue;
    /** 0–1, this stage's value against the largest stage. Drives the bar. */
    share: number;
}

export interface OrderPipeline {
    currency: string;
    stages: PipelineStage[];
}

export interface OrderStatusCount {
    key: OrderStatusKey;
    label: string;
    count: number;
}

export interface OrderStatusCounts {
    total: number;
    statuses: OrderStatusCount[];
}

export interface OrderFilters {
    status: string | null;
    search: string | null;
    customer_id: string | null;
    /** The customer's sales channel (App\Enums\CustomerType) — orders have no channel of their own. */
    channel: string | null;
    period: string | null;
    /** `YYYY-MM-DD`. Set by the Custom tab and beats `period` when present. */
    from: string | null;
    to: string | null;
}

/** One entry of the Create Order / Orders/{order}/items `items` array. */
export interface LinePayload {
    inventory_item_id: string | null;
    quantity: number;
    unit_type: string;
    unit_price?: number;
    custom_description?: string | null;
    /** Inertia's payload type requires an index signature on nested objects. */
    [key: string]: string | number | boolean | null | undefined;
}

/** A catalog product, as offered by the picker (App\Services\Orders\OrderFormOptions). */
export interface ProductOption {
    id: string;
    name: string;
    sku: string;
    vintage: number | null;
    unit_size: string | null;
    sales_unit: string;
    bottles_per_case: number | null;
    list_price: MoneyValue | null;
}

/**
 * A line being drafted client-side — CreateOrderPanel's whole order, or
 * OrderViewPanel's "Add item" panel on an existing one. Shared by
 * OrderLineFields.vue, the one place this catalog-vs-custom split (which
 * StoreOrderRequest/AddOrderItemsRequest both enforce) is drawn.
 */
export interface OrderLineDraft {
    key: string;
    inventory_item_id: string | null;
    custom_description: string | null;
    quantity: number;
    unit_type: string;
    /** Minor units. Only sent for custom lines; the server prices catalog ones. */
    unit_price: number | null;
    /** Catalog list price, for the estimate only. */
    preview: MoneyValue | null;
    label: string;
    meta: string | null;
}
