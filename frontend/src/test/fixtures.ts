import type {
  AuthSession,
  ConsignmentSummary,
  Customer,
  DashboardSummary,
  InventoryAnalytics,
  InventoryImage,
  InventoryItem,
  Invitation,
  Member,
  Notification,
  Order,
  OrderComment,
  OrderItem,
  OrderStatusEntry,
  OrganizationSettings,
  PricingTier,
  RecipeLine,
  StockMovement,
  TenantMembership,
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
    ...overrides,
  };
}

export function makeItem(overrides: Partial<InventoryItem> = {}): InventoryItem {
  return {
    id: "itm_1",
    name: "Plavac Mali 2021",
    sku: "PM-2021",
    category: "FINISHED",
    group: null,
    subcategory: null,
    vintage: 2021,
    unit_size: null,
    unit: "bottle",
    sales_unit: null,
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

export function makeRecipeLine(overrides: Partial<RecipeLine> = {}): RecipeLine {
  return {
    input_id: "itm_2",
    input_name: "Graševina 2022",
    input_sku: "GR-2022",
    input_unit: "bottle",
    quantity: "2.000",
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

export function makeAnalytics(overrides: Partial<InventoryAnalytics> = {}): InventoryAnalytics {
  return {
    stock_levels: [
      { name: "Plavac Mali 2021", stock: "120" },
      { name: "Graševina 2022", stock: "40" },
    ],
    value: { total: 449900, currency: "EUR", categories: [{ category: "FINISHED", value: 449900 }] },
    low_stock: {
      below: [{ name: "Capsules", stock: "6", min: "24" }],
      approaching: [{ name: "Corks", stock: "18", min: "20" }],
    },
    ...overrides,
  };
}

export function makeDashboard(overrides: Partial<DashboardSummary> = {}): DashboardSummary {
  return {
    range: "30D",
    currency: "EUR",
    stats: { total_orders: 128, customers: 42, revenue: 2486000, low_stock: 3 },
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
    link: "/orders/ord_1",
    is_read: false,
    created_at: "2026-06-01T10:05:00+00:00",
    ...overrides,
  };
}
