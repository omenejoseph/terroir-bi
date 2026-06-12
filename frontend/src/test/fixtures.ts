import { MODULES } from "@/lib/types";
import type {
  ArAging,
  AuthSession,
  CashFlow,
  ConsignmentSummary,
  Cost,
  CostAnalytics,
  Customer,
  CustomerAnalytics,
  MergePreview,
  CustomerOrderAnalytics,
  PublicCatalog,
  DashboardSummary,
  Inflow,
  InflowAnalytics,
  InflowChange,
  InventoryAnalytics,
  InventorySpend,
  InventoryCheckSummary,
  InventoryCheckDetail,
  InventoryDocument,
  InventoryImage,
  InventoryItem,
  Invitation,
  Member,
  Notification,
  Order,
  OrderComment,
  OrderItem,
  OrderPayments,
  OrderStatusEntry,
  OrganizationSettings,
  PricingTier,
  ItemTierPrice,
  ItemCustomerPrice,
  BottleAnalysis,
  RecipeLine,
  StockAnalytics,
  StockMovement,
  Supplier,
  SupplierOrder,
  SupplierMergePreview,
  SupplierPortal,
  SupplierPriceItem,
  TenantAccess,
  TenantMembership,
  WorkOrder,
  WorkOrderStats,
} from "@/lib/types";

const money = (minor: number) => ({ minor, currency: "EUR", formatted: `€${(minor / 100).toFixed(2)}` });

/** Reusable test fixtures shaped like the real API DTOs. */

export const tenantA: TenantMembership = {
  tenant_id: "ten_a",
  name: "Vinarija Alpha",
  slug: "alpha",
  roles: ["ADMIN"],
  status: "active",
};

export const tenantB: TenantMembership = {
  tenant_id: "ten_b",
  name: "Vinarija Beta",
  slug: "beta",
  roles: ["TEAM"],
  status: "active",
};

export function makeSettings(overrides: Partial<OrganizationSettings> = {}): OrganizationSettings {
  return {
    name: "Vinarija Alpha",
    default_locale: "hr",
    default_currency: "EUR",
    timezone: "Europe/Zagreb",
    company_oib: null,
    ...overrides,
  };
}

export function makeSession(overrides: Partial<AuthSession> = {}): AuthSession {
  return {
    token: "tok_test",
    user: {
      id: "usr_1",
      first_name: "Ada",
      middle_name: null,
      last_name: "Lovelace",
      name: "Ada Lovelace",
      email: "ada@example.com",
    },
    active_tenant_id: tenantA.tenant_id,
    roles: ["ADMIN"],
    tenants: [tenantA, tenantB],
    settings: makeSettings(),
    modules: [...MODULES],
    access: makeAccess(),
    ...overrides,
  };
}

/** Subscription access state — defaults to full; pass overrides for grace/blocked. */
export function makeAccess(overrides: Partial<TenantAccess> = {}): TenantAccess {
  return {
    level: "full",
    status: "active",
    trial_ends_at: null,
    current_period_end: null,
    grace_full_until: null,
    grace_readonly_until: null,
    days_remaining: null,
    ...overrides,
  };
}

export function makeItem(overrides: Partial<InventoryItem> = {}): InventoryItem {
  return {
    id: "itm_1",
    name: "Plavac Mali 2021",
    sku: "PM-2021",
    description: null,
    category: "FINISHED",
    group: null,
    subcategory: null,
    vintage: 2021,
    unit_size: null,
    unit: "bottle",
    sales_unit: "bottles",
    current_stock: "120",
    min_stock: 10,
    is_active: true,
    is_for_sale: true,
    hide_from_portal: false,
    sort_order: 1,
    bottles_per_case: 6,
    pack_size: null,
    base_product_id: null,
    is_auto_created: false,
    default_price: { minor: 1500, currency: "EUR", formatted: "€15.00" },
    cost_per_unit: { minor: 700, currency: "EUR", formatted: "€7.00" },
    ...overrides,
  };
}

export function makeImage(overrides: Partial<InventoryImage> = {}): InventoryImage {
  return {
    id: "img_1",
    alt: null,
    content_type: "image/webp",
    size_bytes: 12345,
    sort_order: 1,
    url: "https://bucket.test/read/img_1.webp",
    ...overrides,
  };
}

export function makeInventoryDocument(
  overrides: Partial<InventoryDocument> = {},
): InventoryDocument {
  return {
    id: "doc_1",
    name: "lab-report.pdf",
    content_type: "application/pdf",
    size_bytes: 204800,
    url: "https://bucket.test/read/doc_1.pdf",
    ...overrides,
  };
}

export function makeMovement(overrides: Partial<StockMovement> = {}): StockMovement {
  return {
    id: "mv_1",
    type: "MANUAL_IN",
    quantity: "25.000",
    unit: "bottle",
    reference: "PO-1",
    note: null,
    is_reconciliation: false,
    created_at: "2026-06-01T10:00:00+00:00",
    ...overrides,
  };
}

export function makeInventoryCheck(
  overrides: Partial<InventoryCheckSummary> = {},
): InventoryCheckSummary {
  return {
    id: "chk_1",
    reference: "INVCHECK-2026-06-10",
    performed_by: "Ada Lovelace",
    items_counted: 2,
    items_adjusted: 1,
    net_difference: "-10.000",
    created_at: "2026-06-10T10:00:00+00:00",
    ...overrides,
  };
}

export function makeInventoryCheckDetail(
  overrides: Partial<InventoryCheckDetail> = {},
): InventoryCheckDetail {
  return {
    ...makeInventoryCheck(),
    lines: [
      {
        name: "Premium Red Blend",
        sku: "FP-REDWINE-001",
        system_count: "100.000",
        physical_count: "90.000",
        difference: "-10.000",
      },
    ],
    ...overrides,
  };
}

export function makeInventorySpend(overrides: Partial<InventorySpend> = {}): InventorySpend {
  const zeroSummary = {
    units_exited: 60,
    movements: 2,
    cost_value: money(24000),
    revenue: money(120000),
    distinct_skus: 1,
  };
  return {
    period: { from: "2026-06-01", to: "2026-06-10", days: 10 },
    previous_period: { from: "2026-05-22", to: "2026-05-31", days: 10 },
    summary: zeroSummary,
    previous: {
      units_exited: 10,
      movements: 1,
      cost_value: money(4000),
      revenue: money(0),
      distinct_skus: 1,
    },
    daily: [
      { date: "2026-06-03", units: 40 },
      { date: "2026-06-07", units: 20 },
    ],
    per_product: [
      {
        id: "itm_1",
        name: "Premium Red Blend",
        sku: "FP-REDWINE-001",
        vintage: 2024,
        group: "Wine",
        subcategory: "Red Wine",
        on_hand: 150,
        units_exited: 60,
        prev_units_exited: 10,
        velocity_per_day: "6.00",
        days_left: 25,
        cost_of_exits: money(24000),
        revenue: money(120000),
        daily: [0, 0, 40, 0, 0, 0, 20, 0, 0, 0],
      },
    ],
    ...overrides,
  };
}

export function makeBottleAnalysis(overrides: Partial<BottleAnalysis> = {}): BottleAnalysis {
  return {
    id: "ba_1",
    analyzed_on: "2026-06-10",
    note: null,
    ph: null,
    total_acidity: null,
    volatile_acidity: null,
    alcohol: null,
    residual_sugar: null,
    free_so2: null,
    total_so2: null,
    temperature: null,
    density: null,
    tpi: null,
    ...overrides,
  };
}

export function makeStockAnalytics(overrides: Partial<StockAnalytics> = {}): StockAnalytics {
  return {
    period: "30d",
    current: {
      stock_bottles: 150,
      unit: "bottles",
      bottles_per_case: 12,
      min_stock_bottles: 50,
      cost_per_bottle: null,
      selling_per_bottle: money(2999),
    },
    realized: {
      mean_price: money(1999),
      rebate_percent: "33.3",
      rebate_amount: money(1000),
      margin_percent: "100.0",
      margin_amount: money(1999),
      sales_value: money(299850),
      bottles_sold: 150,
    },
    exits: {
      bottles_exited: 130,
      cost_of_exits: null,
      revenue_realized: money(299850),
      mean_margin_percent: "100.0",
      velocity_per_day: "4.33",
      days_of_stock_left: 35,
    },
    channels: [
      { channel: "sales", bottles: 100 },
      { channel: "manual", bottles: 20 },
      { channel: "production", bottles: 10 },
    ],
    ...overrides,
  };
}

export function makeRecipeLine(overrides: Partial<RecipeLine> = {}): RecipeLine {
  return {
    input_id: "itm_2",
    input_name: "Graševina 2022",
    input_sku: "GR-2022",
    input_unit: "bottle",
    quantity: "2.000",
    input_group: "Wine",
    input_stock: "500",
    ...overrides,
  };
}

export function makeMember(overrides: Partial<Member> = {}): Member {
  return {
    id: "mem_1",
    user_id: "usr_1",
    name: "Ada Lovelace",
    email: "ada@example.com",
    roles: ["ADMIN"],
    status: "active",
    ...overrides,
  };
}

export function makeInvitation(overrides: Partial<Invitation> = {}): Invitation {
  return {
    id: "inv_1",
    email: "newhire@example.com",
    roles: ["TEAM"],
    expires_at: "2026-07-01T00:00:00+00:00",
    pending: true,
    ...overrides,
  };
}

export function makePricingTier(overrides: Partial<PricingTier> = {}): PricingTier {
  return {
    id: "tier_1",
    name: "Wholesale",
    description: null,
    rebate_percent: "10.00",
    customers_count: 3,
    ...overrides,
  };
}

export function makeItemTierPrice(overrides: Partial<ItemTierPrice> = {}): ItemTierPrice {
  return {
    pricing_tier_id: "tier_1",
    tier_name: "Wholesale",
    rebate_percent: "10.00",
    price: money(1999),
    ...overrides,
  };
}

export function makeItemCustomerPrice(
  overrides: Partial<ItemCustomerPrice> = {},
): ItemCustomerPrice {
  return {
    customer_id: "cus_1",
    company_name: "Konoba Riva",
    price: money(1500),
    ...overrides,
  };
}

export function makeCustomer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: "cus_1",
    company_name: "Acme Corporation",
    contact_name: "Jane Doe",
    email: "orders@acme.com",
    phone: null,
    address: null,
    city: null,
    state: null,
    zip: null,
    country: null,
    oib: null,
    customer_type: null,
    notes: null,
    is_active: true,
    rebate_percent: "5.00",
    effective_rebate_percent: "5.00",
    hide_prices: false,
    is_agency: false,
    allow_single_bottle: false,
    exclude_from_stats: false,
    reorder_contacted_at: null,
    has_order_token: false,
    pricing_tier: { id: "tier_1", name: "Wholesale", rebate_percent: "10.00" },
    ...overrides,
  };
}

export function makeMergePreview(
  winnerId = "cus_1",
  loserIds: string[] = ["cus_2"],
  applied = false,
): MergePreview {
  return {
    applied,
    winner: { id: winnerId, company_name: "Acme Corporation" },
    losers: loserIds.map((id) => ({
      id,
      company_name: "Retail Shop LLC",
      orders: 3,
      price_reassign: 1,
      price_drop: 0,
      override_reassign: 0,
      override_drop: 0,
    })),
    totals: {
      orders: 3 * loserIds.length,
      price_reassign: loserIds.length,
      price_drop: 0,
      override_reassign: 0,
      override_drop: 0,
      losers_deleted: loserIds.length,
    },
  };
}

export function makeCustomerAnalytics(
  overrides: Partial<CustomerAnalytics> = {},
): CustomerAnalytics {
  return {
    summary: {
      active_customers: 1,
      revenue_12m: money(10000),
      top_customer: {
        id: "cus_1",
        company_name: "Acme Corporation",
        contact_name: "John Smith",
        revenue_12m: money(9995),
      },
    },
    customers: [
      {
        customer_id: "cus_1",
        company_name: "Acme Corporation",
        contact_name: "John Smith",
        revenue_12m: money(9995),
        revenue_all_time: money(9995),
        order_count_12m: 1,
        avg_order_value: money(9995),
        last_order_date: "2026-06-08T00:00:00+00:00",
        days_since_last_order: 2,
        median_gap_days: null,
        expected_next_order_date: null,
      },
    ],
    ...overrides,
  };
}

export function makeCustomerOrderAnalytics(
  overrides: Partial<CustomerOrderAnalytics> = {},
): CustomerOrderAnalytics {
  return {
    total_revenue: money(120000),
    this_year: money(50000),
    last_year: money(40000),
    last_order_date: "2026-05-01T10:00:00+00:00",
    yoy_growth_percent: "25.00",
    annual_projection: money(110000),
    expected_next_order_date: "2026-07-01T10:00:00+00:00",
    next_quarter_projection: money(30000),
    ...overrides,
  };
}

export function makePublicCatalog(overrides: Partial<PublicCatalog> = {}): PublicCatalog {
  return {
    customer: { company_name: "Acme Corporation", hide_prices: false, allow_single_bottle: false },
    products: [
      {
        id: "itm_1",
        name: "Plavac Mali 2021",
        sku: "PM-2021",
        vintage: "2021",
        unit: "cases",
        bottles_per_case: 12,
        price: money(15000),
      },
    ],
    ...overrides,
  };
}

export function makeAnalytics(overrides: Partial<InventoryAnalytics> = {}): InventoryAnalytics {
  return {
    summary: {
      total_active: 6,
      low_stock: 1,
      out_of_stock: 0,
      for_sale: 1,
      by_category: { FINISHED: 1, SEMI_FINISHED: 1, RAW_MATERIAL: 4 },
      priced_count: 1,
      sale_value: money(449850),
      production_value: money(0),
      margin_percent: "100",
    },
    portfolio_exits: {
      period_days: 90,
      external: {
        units_exited: 100,
        cost_of_exits: null,
        revenue_realized: money(199900),
        mean_margin_percent: "100.0",
        mean_price: money(1999),
        off_target_percent: "33.3",
      },
      blended: {
        units_exited: 130,
        cost_of_exits: null,
        revenue_realized: money(199900),
        velocity_per_day: "1.4",
      },
    },
    movements_12m: [
      { month: "2026-05", in: 0, out: 10 },
      { month: "2026-06", in: 0, out: 120 },
    ],
    top_products: [{ name: "Premium Red Blend", value: 449850 }],
    by_group: [
      { group: "Packaging", count: 4 },
      { group: "Wine", count: 2 },
    ],
    stock_levels: [{ name: "Premium Red Blend", stock: "150" }],
    value: { total: 449850, currency: "EUR", categories: [{ category: "FINISHED", value: 449850 }] },
    low_stock: {
      below: [{ name: "Cork", stock: "0", min: "100" }],
      approaching: [],
    },
    ...overrides,
  };
}

export function makeDashboard(overrides: Partial<DashboardSummary> = {}): DashboardSummary {
  return {
    range: "30D",
    currency: "EUR",
    stats: {
      total_orders: 128,
      customers: 42,
      revenue: 2486000,
      low_stock: 3,
      outstanding_ar: 250000,
      tasks_overdue: 3,
    },
    orders: [
      { label: "Jun 1", value: 5 },
      { label: "Jun 2", value: 7 },
    ],
    revenue: [
      { label: "Jun 1", value: 180000 },
      { label: "Jun 2", value: 240000 },
    ],
    order_status: [
      { key: "received", value: 36 },
      { key: "inProcess", value: 23 },
      { key: "readyToShip", value: 15 },
      { key: "shipped", value: 54 },
    ],
    top_products: [{ name: "Premium Red Blend 2024", value: 12 }],
    stock_watch: [{ name: "Capsules", stock: "6", min: "24" }],
    recent_orders: [
      { id: "ORD-20260042", customer: "Acme Corporation", items: 5, total: 9995, status: "received", date: "Jun 8" },
    ],
    ...overrides,
  };
}
export function makeOrderItem(overrides: Partial<OrderItem> = {}): OrderItem {
  return {
    id: "oi_1",
    inventory_item_id: "itm_1",
    name: "Plavac Mali 2021",
    sku: "PM-2021",
    quantity: 6,
    unit_type: "bottles",
    unit_price: money(1500),
    total: money(9000),
    custom_description: null,
    cost_per_unit: money(700),
    ...overrides,
  };
}

export function makeOrderStatusEntry(overrides: Partial<OrderStatusEntry> = {}): OrderStatusEntry {
  return {
    status: "RECEIVED",
    note: null,
    changed_by: { id: "usr_1", name: "Ada Lovelace" },
    created_at: "2026-06-01T10:00:00+00:00",
    ...overrides,
  };
}

export function makeOrderComment(overrides: Partial<OrderComment> = {}): OrderComment {
  return {
    id: "cmt_1",
    content: "Packed and ready.",
    author: { id: "usr_1", name: "Ada Lovelace" },
    created_at: "2026-06-01T11:00:00+00:00",
    ...overrides,
  };
}

export function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    id: "ord_1",
    order_number: "ORD-1001",
    status: "RECEIVED",
    total_amount: money(9000),
    notes: null,
    customer: { id: "cus_1", company_name: "Acme Corporation" },
    created_by: { id: "usr_1", name: "Ada Lovelace" },
    is_backorder: false,
    backorder_date: null,
    shipping_cost: null,
    shipping_paid_by_us: false,
    is_consignment: false,
    consignment_closed_at: null,
    created_at: "2026-06-01T10:00:00+00:00",
    items: [makeOrderItem()],
    status_history: [makeOrderStatusEntry()],
    comments: [],
    ...overrides,
  };
}

export function makeConsignmentSummary(overrides: Partial<ConsignmentSummary> = {}): ConsignmentSummary {
  return {
    is_consignment: true,
    closed_at: null,
    lines: [
      {
        order_item_id: "oi_1",
        name: "Plavac Mali 2021",
        placed: 12,
        sold: 4,
        returned: 0,
        remaining: 8,
        per_bottle_price: money(1500),
        revenue: money(6000),
        cogs: money(2800),
      },
    ],
    totals: {
      placed: 12,
      sold: 4,
      returned: 0,
      remaining: 8,
      revenue: money(6000),
      cogs: money(2800),
      profit: money(3200),
      margin_percent: "53.33",
    },
    history: [{ id: "cr_1", kind: "SALE", date: "2026-06-02T10:00:00+00:00", note: null }],
    ...overrides,
  };
}

export function makeNotification(overrides: Partial<Notification> = {}): Notification {
  return {
    id: "ntf_1",
    type: "NEW_ORDER",
    title: "New order ORD-1001",
    body: "Acme Corporation placed an order.",
    data: { order_id: "ord_1" },
    is_read: false,
    created_at: "2026-06-01T10:05:00+00:00",
    ...overrides,
  };
}

// ── Finance: inflows / payments / A/R ─────────────────────────────────────────

export function makeInflow(overrides: Partial<Inflow> = {}): Inflow {
  return {
    id: "inf_1",
    customer_id: "cus_1",
    order_id: "ord_1",
    order_number: "ORD-1001",
    changes_count: 0,
    date: "2026-06-02T10:00:00+00:00",
    amount: money(50000),
    status: "RECEIVED",
    is_credit_note: false,
    category: "Order payment",
    reference: null,
    payment_method: "bank_transfer",
    notes: null,
    due_date: null,
    received_at: "2026-06-02T10:00:00+00:00",
    created_at: "2026-06-02T10:00:00+00:00",
    ...overrides,
  };
}

export function makeInflowAnalytics(overrides: Partial<InflowAnalytics> = {}): InflowAnalytics {
  return {
    period: { from: "2026-06-01T00:00:00+00:00", to: "2026-06-30T00:00:00+00:00" },
    invoiced: { total: money(16000), count: 2 },
    collected: { total: money(10000), count: 1 },
    pending: { total: money(6000), count: 1 },
    net_cash_flow: { net: money(6000), inflows: money(10000), costs: money(4000) },
    avg_days_to_collect: { days: 5, count: 1 },
    avg_inflow: { avg: money(8000) },
    by_category: [
      { name: "Invoice", total: money(16000) },
      { name: "Grant", total: money(5000) },
    ],
    by_customer: [{ customer_id: "cus_1", company_name: "Konoba", total: money(16000) }],
    over_time: [
      { month: "2026-05", total: money(8000) },
      { month: "2026-06", total: money(16000) },
    ],
    cash_flow: [{ month: "2026-06", inflows: money(10000), costs: money(4000), net: money(6000) }],
    ...overrides,
  };
}

export function makeInflowChange(overrides: Partial<InflowChange> = {}): InflowChange {
  return {
    id: "ic_1",
    changes: [
      { field: "amount", old: 50000, new: 60000 },
      { field: "status", old: "PENDING", new: "RECEIVED" },
    ],
    changed_by: "Ada Lovelace",
    created_at: "2026-06-05T10:00:00+00:00",
    ...overrides,
  };
}

export function makeOrderPayments(overrides: Partial<OrderPayments> = {}): OrderPayments {
  return {
    summary: {
      amount_paid: money(50000),
      balance_due: money(40000),
      status: "PARTIAL",
    },
    payments: [makeInflow()],
    ...overrides,
  };
}

export function makeArAging(overrides: Partial<ArAging> = {}): ArAging {
  return {
    total_outstanding: money(730000),
    buckets: {
      current: money(400000),
      "31_60": money(200000),
      "61_90": money(100000),
      "90_plus": money(30000),
    },
    by_customer: [
      { customer_id: "cus_1", company_name: "Acme Corporation", orders: 3, outstanding: money(730000) },
    ],
    ...overrides,
  };
}

// ── Costs ─────────────────────────────────────────────────────────────────────

export function makeCost(overrides: Partial<Cost> = {}): Cost {
  return {
    id: "cost_1",
    date: "2026-06-01T00:00:00+00:00",
    total_amount: money(12000),
    vat_amount: money(2400),
    category: "Utilities",
    description: "June electricity",
    reference: "INV-2026-06",
    status: "PENDING",
    payment_method: null,
    notes: null,
    paid_at: null,
    due_date: "2026-06-30T00:00:00+00:00",
    supplier: { id: "sup_1", company_name: "Vinogradar d.o.o." },
    ...overrides,
  };
}

export function makeCostAnalytics(overrides: Partial<CostAnalytics> = {}): CostAnalytics {
  return {
    period: { from: "2026-05-01T00:00:00+00:00", to: "2026-06-01T00:00:00+00:00" },
    total_spend: money(70000),
    unpaid: money(50000),
    invoiced: { total: money(60000), count: 4 },
    paid: { total: money(20000), count: 1 },
    unpaid_invoices: { total: money(40000), count: 3 },
    avg_invoice: { avg: money(15000), max: money(30000) },
    avg_days_to_pay: { days: 12.5, count: 1 },
    gross_margin: { percent: "73.1", revenue: money(260000) },
    yoy: {
      current_year: 2026,
      previous_year: 2025,
      months: Array.from({ length: 12 }, (_, i) => ({
        month: i + 1,
        current: money(i < 6 ? (i + 1) * 1000 : 0),
        previous: money(i < 6 ? i * 800 : 0),
      })),
    },
    top_costs: [
      { id: "cost_1", date: "2026-05-20T00:00:00+00:00", category: "Glass", supplier_name: "Vinogradar d.o.o.", total: money(50000) },
      { id: "cost_2", date: "2026-05-10T00:00:00+00:00", category: "Corks", supplier_name: null, total: money(20000) },
    ],
    by_status: [
      { status: "PENDING", count: 1, total: money(50000) },
      { status: "PAID", count: 1, total: money(20000) },
    ],
    by_category: [
      { name: "Glass", total: money(50000) },
      { name: "Corks", total: money(20000) },
    ],
    by_supplier: [{ supplier_id: "sup_1", company_name: "Vinogradar d.o.o.", total: money(50000) }],
    over_time: [
      { month: "2026-05", total: money(30000) },
      { month: "2026-06", total: money(40000) },
    ],
    profit_loss: [
      { month: "2026-05", revenue: money(120000), costs: money(30000), profit: money(90000) },
      { month: "2026-06", revenue: money(140000), costs: money(40000), profit: money(100000) },
    ],
    ...overrides,
  };
}

// ── Suppliers + price lists + purchase orders ─────────────────────────────────

export function makePriceItem(overrides: Partial<SupplierPriceItem> = {}): SupplierPriceItem {
  return {
    id: "pli_1",
    inventory_item_id: null,
    description: "Natural cork 44mm",
    unit_price: money(25),
    unit: "units",
    notes: null,
    last_updated: "2026-06-01T00:00:00+00:00",
    ...overrides,
  };
}

export function makeSupplier(overrides: Partial<Supplier> = {}): Supplier {
  return {
    id: "sup_1",
    company_name: "Vinogradar d.o.o.",
    contact_name: "Marko Marić",
    email: "sales@vinogradar.hr",
    phone: null,
    address: null,
    city: null,
    country: null,
    tax_id: "12345678901",
    bank_account: null,
    payment_terms: "Net 30",
    notes: null,
    is_active: true,
    exclude_from_stats: false,
    price_items_count: 1,
    ...overrides,
  };
}

export function makeSupplierOrder(overrides: Partial<SupplierOrder> = {}): SupplierOrder {
  return {
    id: "po_1",
    order_number: "PO-00001",
    status: "DRAFT",
    total_amount: money(12500),
    notes: null,
    sent_at: null,
    expected_at: "2026-07-01T00:00:00+00:00",
    received_at: null,
    supplier: { id: "sup_1", company_name: "Vinogradar d.o.o." },
    items: [
      {
        id: "poi_1",
        inventory_item_id: "itm_1",
        description: "Cork 44mm",
        quantity: "500",
        unit: "units",
        unit_price: money(25),
        total: money(12500),
      },
    ],
    ...overrides,
  };
}

export function makeSupplierMergePreview(
  winnerId = "sup_1",
  loserIds: string[] = ["sup_2"],
  applied = false,
): SupplierMergePreview {
  return {
    applied,
    winner: { id: winnerId, company_name: "Vinogradar d.o.o." },
    losers: loserIds.map((id) => ({
      id,
      company_name: "Glass Co.",
      orders: 2,
      costs: 1,
      price_reassign: 3,
      price_drop: 1,
    })),
    totals: {
      orders: 2 * loserIds.length,
      costs: loserIds.length,
      price_reassign: 3 * loserIds.length,
      price_drop: loserIds.length,
      losers_deleted: loserIds.length,
    },
  };
}

export function makeSupplierPortal(overrides: Partial<SupplierPortal> = {}): SupplierPortal {
  return {
    supplier: { company_name: "Serrano and Crawford Inc", contact_name: "Hiram Richards" },
    orders: [makeSupplierOrder({ id: "po_1", order_number: "PO-1", status: "SENT" })],
    price_items: [
      { id: "pli_1", description: "Natural cork 44mm", unit_price: money(2500), unit: "units" },
    ],
    ...overrides,
  };
}

// ── Cash flow ─────────────────────────────────────────────────────────────────

export function makeCashFlow(overrides: Partial<CashFlow> = {}): CashFlow {
  const month = (i: number) => `2025-${String(((i % 12) + 1)).padStart(2, "0")}`;
  const historical = Array.from({ length: 12 }, (_, i) => ({
    month: month(i),
    revenue: money(100000 + i * 1000),
    costs: money(60000 + i * 500),
    net: money(40000 + i * 500),
    is_projection: false,
  }));
  const forecast = Array.from({ length: 6 }, (_, i) => ({
    month: `2026-${String((i % 12) + 1).padStart(2, "0")}`,
    revenue: money(112000 + i * 1000),
    costs: money(66000),
    net: money(46000 + i * 1000),
    is_projection: true,
  }));
  return {
    currency: "EUR",
    historical,
    forecast,
    pending: {
      receivable: money(20000),
      receivable_count: 4,
      payable: money(5000),
      payable_count: 2,
      net: money(15000),
    },
    summary: {
      avg_monthly_revenue: money(105000),
      avg_monthly_costs: money(62000),
      avg_monthly_net: money(43000),
      revenue_growth_percent: "4.00",
    },
    ...overrides,
  };
}

// ── Tasks / work orders ───────────────────────────────────────────────────────

export function makeWorkOrder(overrides: Partial<WorkOrder> = {}): WorkOrder {
  return {
    id: "task_1",
    title: "Bottle Plavac batch",
    description: null,
    category: null,
    priority: "MEDIUM",
    status: "TODO",
    start_date: null,
    // Far-future so the default item is never spuriously "overdue" (which would
    // render a board badge colliding with the "Overdue" stat tile).
    due_date: "2030-01-01T00:00:00+00:00",
    completed_at: null,
    sort_order: 1,
    assignee: { id: "usr_1", name: "Ada Lovelace" },
    ...overrides,
  };
}

export function makeWorkOrderStats(overrides: Partial<WorkOrderStats> = {}): WorkOrderStats {
  return { todo: 2, in_progress: 1, done: 5, overdue: 1, ...overrides };
}
