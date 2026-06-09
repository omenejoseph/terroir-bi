/**
 * TypeScript mirrors of the Laravel API DTOs. Keep this file in sync with the
 * backend's *Data classes (app/DataTransferObjects). This is the contract.
 */

/** Every endpoint wraps its payload in { data, meta? }. */
export interface ApiEnvelope<T> {
  data: T;
  meta?: PaginationMeta;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** Laravel validation / error body. */
export interface ApiErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}

// ── Auth ────────────────────────────────────────────────────────────────────

export interface User {
  id: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  name: string;
  email: string;
}

export interface TenantMembership {
  tenant_id: string;
  name: string;
  slug: string;
  roles: string[];
  status: string;
}

/** Returned by login / switch-tenant / me. The `token` is tenant-bound. */
export interface AuthSession {
  token: string | null;
  user: User;
  active_tenant_id: string | null;
  roles: string[];
  tenants: TenantMembership[];
  settings: OrganizationSettings | null;
}

/** Organisation-wide settings for the active tenant (GET/PATCH /settings). */
export interface OrganizationSettings {
  name: string;
  default_locale: string;
  default_currency: string;
  timezone: string;
  company_oib: string | null;
}

/** Payload for PATCH /settings. Currency is read-only and not sent. */
export interface OrganizationSettingsInput {
  name: string;
  default_locale: string;
  timezone: string;
  company_oib?: string | null;
}

// ── Inventory ─────────────────────────────────────────────────────────────────

export interface Money {
  amount: number;
  currency: string;
  formatted?: string;
  [key: string]: unknown;
}

export interface InventoryItem {
  id: string;
  name: string;
  sku: string;
  category: string;
  group: string | null;
  subcategory: string | null;
  vintage: number | null;
  unit_size: string | null;
  unit: string;
  sales_unit: string | null;
  current_stock: string;
  min_stock: string | number | null;
  is_active: boolean;
  is_for_sale: boolean;
  hide_from_portal: boolean | null;
  sort_order: number | null;
  bottles_per_case: number | null;
  pack_size: number | null;
  base_product_id: string | null;
  is_auto_created: boolean | null;
  default_price: Money | null;
  cost_per_unit: Money | null;
}

export interface InventoryQuery {
  search?: string;
  category?: string;
  is_active?: boolean;
  is_for_sale?: boolean;
  sellable?: boolean;
}

/** Inventory categories — mirrors App\Enums\InventoryCategory. */
export const INVENTORY_CATEGORIES = ["FINISHED", "SEMI_FINISHED", "RAW_MATERIAL"] as const;
export type InventoryCategory = (typeof INVENTORY_CATEGORIES)[number];

/**
 * Common units of measure. The backend stores `unit` as a free string (max 50),
 * so this is a curated convenience list, not a hard enum — extend freely.
 */
export const INVENTORY_UNITS = ["bottle", "case", "liter", "kg", "unit"] as const;
export type InventoryUnit = (typeof INVENTORY_UNITS)[number];

/** Payload for POST /inventory-items. Prices are integer minor units (e.g. cents). */
export interface InventoryItemInput {
  name: string;
  sku: string;
  category: InventoryCategory;
  unit: string;
  group?: string | null;
  subcategory?: string | null;
  vintage?: string | null;
  unit_size?: string | null;
  sales_unit?: string | null;
  min_stock?: number | null;
  default_price?: number | null;
  pack_size?: number;
  is_active?: boolean;
  is_for_sale?: boolean;
  hide_from_portal?: boolean;
  base_product_id?: string | null;
}

/** A distinct category → group → subcategory combination in use (GET .../taxonomy). */
export interface TaxonomyEntry {
  category: string;
  group: string;
  subcategory: string | null;
}

export interface StockWatchItem {
  name: string;
  stock: string;
  min: string;
}

/** GET /inventory-items/analytics — read-optimised inventory metrics. */
export interface InventoryAnalytics {
  stock_levels: { name: string; stock: string }[];
  value: {
    total: number;
    currency: string;
    categories: { category: string; value: number }[];
  };
  low_stock: { below: StockWatchItem[]; approaching: StockWatchItem[] };
}

// ── Customers & pricing ───────────────────────────────────────────────────────

export interface PricingTier {
  id: string;
  name: string;
  description: string | null;
  rebate_percent: string;
  customers_count?: number;
}

/** Payload for POST /pricing-tiers. */
export interface PricingTierInput {
  name: string;
  description?: string | null;
  rebate_percent?: number;
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
  customer_type: string | null;
  notes: string | null;
  is_active: boolean;
  rebate_percent: string;
  effective_rebate_percent: string;
  hide_prices: boolean;
  is_agency: boolean | null;
  allow_single_bottle: boolean | null;
  exclude_from_stats: boolean | null;
  reorder_contacted_at: string | null;
  has_order_token: boolean;
  pricing_tier: { id: string; name: string; rebate_percent: string } | null;
}

/** Payload for POST/PATCH /customers. */
export interface CustomerInput {
  company_name: string;
  email: string;
  contact_name?: string | null;
  phone?: string | null;
  address?: string | null;
  city?: string | null;
  zip?: string | null;
  country?: string | null;
  oib?: string | null;
  customer_type?: string | null;
  pricing_tier_id?: string | null;
  rebate_percent?: number;
  hide_prices?: boolean;
  is_agency?: boolean;
  allow_single_bottle?: boolean;
  exclude_from_stats?: boolean;
  is_active?: boolean;
}

export interface CustomerQuery {
  search?: string;
  is_active?: boolean;
  pricing_tier_id?: string;
}

// ── Team (members & invitations) ──────────────────────────────────────────────

/** Roles a user can hold in a tenant — mirrors App\Enums\TenantRole. */
export const TENANT_ROLES = [
  "ADMIN",
  "TEAM",
  "CELLAR",
  "ORDERS",
  "MANAGER",
  "SALES",
  "HOSPITALITY",
  "KITCHEN",
  "EMPLOYEE",
  "WINE_CLUB",
  "INVENTORY",
] as const;
export type TenantRole = (typeof TENANT_ROLES)[number];

export type MembershipStatus = "active" | "suspended";

export interface Member {
  id: string;
  user_id: string;
  name: string;
  email: string;
  roles: string[];
  status: string;
}

export interface Invitation {
  id: string;
  email: string;
  roles: string[];
  expires_at: string;
  pending: boolean;
  accept_token?: string;
}

export interface InviteInput {
  email: string;
  roles: string[];
}

export interface MemberUpdate {
  roles?: string[];
  status?: MembershipStatus;
}

export interface SeriesPoint {
  label: string;
  value: number;
}

/** GET /dashboard — aggregated summary. Money fields are integer minor units. */
export interface DashboardSummary {
  range: string;
  currency: string;
  stats: { total_orders: number; customers: number; revenue: number; low_stock: number };
  orders: SeriesPoint[];
  revenue: SeriesPoint[];
  order_status: { key: string; value: number }[];
  top_products: { name: string; value: number }[];
  stock_watch: StockWatchItem[];
  recent_orders: {
    id: string;
    customer: string;
    items: number;
    total: number;
    status: string;
    date: string;
  }[];
}

/** Payload for PATCH /inventory-items/{id} — every field optional. */
export type InventoryItemUpdate = Partial<InventoryItemInput>;

/**
 * Stock movement types — mirrors App\Enums\StockMovementType. Only the manual
 * ones are user-selectable; production and order movements are system-driven.
 */
export const MANUAL_STOCK_MOVEMENTS = ["MANUAL_IN", "MANUAL_OUT", "ADJUSTMENT"] as const;
export type StockMovementType =
  | (typeof MANUAL_STOCK_MOVEMENTS)[number]
  | "PRODUCTION_IN"
  | "PRODUCTION_OUT"
  | "ORDER_DEDUCT";

/** Payload for POST /inventory-items/{id}/stock. `quantity` is a signed delta. */
export interface StockAdjustmentInput {
  type: StockMovementType;
  quantity: number;
  reference?: string | null;
  note?: string | null;
  is_reconciliation?: boolean;
}

/** A stock ledger entry (GET /inventory-items/{id}/movements). */
export interface StockMovement {
  id: string;
  type: StockMovementType;
  quantity: string;
  unit: string | null;
  reference: string | null;
  note: string | null;
  is_reconciliation: boolean;
  created_at: string | null;
}

/**
 * One recipe line with the input resolved (GET /inventory-items/{id}/recipe).
 * `input_id` is null for custom (non-catalog) lines — those carry their own
 * name/unit, surfaced here as input_name/input_unit.
 */
export interface RecipeLine {
  input_id: string | null;
  input_name: string;
  input_sku: string;
  input_unit: string;
  quantity: string;
}

/** Payload line for PUT /inventory-items/{id}/recipe. */
export interface RecipeLineInput {
  input_id: string;
  quantity: number;
}