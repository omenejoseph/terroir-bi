import type { AuthSession, InventoryItem, TenantMembership } from "@/lib/types";

/** Reusable test fixtures shaped like the real API DTOs. */

export const tenantA: TenantMembership = {
  tenant_id: "ten_a",
  name: "Vinarija Alpha",
  slug: "alpha",
  roles: ["owner"],
  status: "active",
};

export const tenantB: TenantMembership = {
  tenant_id: "ten_b",
  name: "Vinarija Beta",
  slug: "beta",
  roles: ["manager"],
  status: "active",
};

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
    roles: ["owner"],
    tenants: [tenantA, tenantB],
    ...overrides,
  };
}

export function makeItem(overrides: Partial<InventoryItem> = {}): InventoryItem {
  return {
    id: "itm_1",
    name: "Plavac Mali 2021",
    sku: "PM-2021",
    category: "wine",
    group: null,
    subcategory: null,
    vintage: 2021,
    unit: "bottle",
    current_stock: "120",
    min_stock: 10,
    is_active: true,
    is_for_sale: true,
    sort_order: 1,
    bottles_per_case: 6,
    default_price: { amount: 1500, currency: "EUR", formatted: "€15.00" },
    cost_per_unit: { amount: 700, currency: "EUR", formatted: "€7.00" },
    ...overrides,
  };
}