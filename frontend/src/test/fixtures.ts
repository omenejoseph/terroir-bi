import type {
  AuthSession,
  Customer,
  DashboardSummary,
  InventoryAnalytics,
  InventoryImage,
  InventoryItem,
  Invitation,
  Member,
  OrganizationSettings,
  PricingTier,
  RecipeLine,
  StockMovement,
  TenantMembership,
} from "@/lib/types";

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
    default_price: { amount: 1500, currency: "EUR", formatted: "€15.00" },
    cost_per_unit: { amount: 700, currency: "EUR", formatted: "€7.00" },
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