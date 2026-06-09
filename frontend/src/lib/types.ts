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
  minor: number;
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

/** An inventory image (GET /inventory-items/{id}/images). `url` is a short-lived presigned GET. */
export interface InventoryImage {
  id: string;
  alt: string | null;
  content_type: string;
  size_bytes: number;
  sort_order: number;
  url: string;
}

/** Payload for POST /inventory-items/{id}/images (after the object is uploaded). */
export interface AttachImageInput {
  key: string;
  content_type: string;
  alt?: string | null;
}

/** Response of POST /uploads/presign — a direct-to-bucket PUT target. */
export interface PresignResult {
  key: string;
  url: string;
  method: string;
  headers: Record<string, string>;
  content_type: string;
  max_bytes: number;
  expires_in: number;
}

/** Input for POST /uploads/presign. */
export interface PresignInput {
  purpose: string;
  filename: string;
  content_type: string;
  size: number;
}

// ── Orders ────────────────────────────────────────────────────────────────────

/** Order lifecycle — mirrors App\Enums\OrderStatus. */
export const ORDER_STATUSES = ["RECEIVED", "IN_PROCESS", "READY_TO_SHIP", "SHIPPED"] as const;
export type OrderStatus = (typeof ORDER_STATUSES)[number];

export type OrderItemUnit = "bottles" | "cases";

export interface OrderItem {
  id: string;
  inventory_item_id: string | null;
  name: string;
  sku: string | null;
  quantity: number;
  unit_type: OrderItemUnit;
  unit_price: Money;
  total: Money;
  custom_description: string | null;
  /** Only present when the viewer has financials.view. */
  cost_per_unit?: Money | null;
}

export interface OrderStatusEntry {
  status: OrderStatus;
  note: string | null;
  changed_by: { id: string; name: string } | null;
  created_at: string | null;
}

export interface OrderComment {
  id: string;
  content: string;
  author: { id: string; name: string } | null;
  created_at: string | null;
}

export interface Order {
  id: string;
  order_number: string;
  status: OrderStatus;
  total_amount: Money;
  notes: string | null;
  customer: { id: string; company_name: string } | null;
  created_by: { id: string; name: string } | null;
  is_backorder: boolean;
  backorder_date: string | null;
  shipping_cost: Money | null;
  shipping_paid_by_us: boolean;
  is_consignment: boolean;
  consignment_closed_at: string | null;
  created_at: string | null;
  items: OrderItem[];
  status_history: OrderStatusEntry[];
  comments: OrderComment[];
}

/** One line in POST /orders or POST /orders/{id}/items. */
export interface OrderItemInput {
  inventory_item_id?: string | null;
  quantity: number;
  unit_type?: OrderItemUnit;
  unit_price?: number;
  custom_description?: string | null;
}

/** Payload for POST /orders. Money inputs are integer minor units. */
export interface OrderInput {
  customer_id: string;
  notes?: string | null;
  status?: OrderStatus;
  is_backorder?: boolean;
  backorder_date?: string | null;
  is_consignment?: boolean;
  shipping_cost?: number | null;
  shipping_paid_by_us?: boolean;
  items: OrderItemInput[];
}

export interface OrderQuery {
  status?: OrderStatus;
  search?: string;
  hide_shipped?: boolean;
  page?: number;
}

/** GET /orders/analytics — money fields are Money objects. */
export interface OrderAnalytics {
  period: { from: string; to: string };
  revenue: Money;
  cogs: Money;
  gross_profit: Money;
  margin_percent: string;
  order_count: number;
  avg_order_value: Money;
  items_with_unknown_cost: number;
  consignment_revenue: Money;
  top_customers: { customer_id: string; company_name: string | null; revenue: Money }[];
  top_products: { inventory_item_id: string; name: string | null; quantity: number; revenue: Money }[];
  low_margin_orders: {
    order_id: string;
    order_number: string;
    revenue: Money;
    cogs: Money;
    margin_percent: string;
  }[];
}

// ── Consignment ───────────────────────────────────────────────────────────────

export interface ConsignmentLine {
  order_item_id: string;
  name: string;
  placed: number;
  sold: number;
  returned: number;
  remaining: number;
  per_bottle_price: Money;
  revenue: Money;
  cogs: Money | null;
}

export interface ConsignmentSummary {
  is_consignment: boolean;
  closed_at: string | null;
  lines: ConsignmentLine[];
  totals: {
    placed: number;
    sold: number;
    returned: number;
    remaining: number;
    revenue: Money;
    cogs: Money;
    profit: Money;
    margin_percent: string;
  };
  history: { id: string; kind: "SALE" | "RETURN"; date: string; note: string | null }[];
}

export interface ConsignmentSaleInput {
  items: { order_item_id: string; quantity: number; unit_price?: number }[];
  note?: string | null;
}

export interface ConsignmentReturnInput {
  items: { order_item_id: string; quantity: number }[];
  note?: string | null;
}

/** Customer-level (FIFO) consignment rollup. */
export interface CustomerConsignmentSummary {
  products: {
    inventory_item_id: string;
    name: string;
    placed: number;
    sold: number;
    returned: number;
    remaining: number;
  }[];
  placements: { order_id: string; order_number: string; placed_at: string; closed_at: string | null }[];
}

export interface PlaceConsignmentInput {
  items: { inventory_item_id: string; quantity: number; unit_type?: OrderItemUnit }[];
  note?: string | null;
}

export interface CustomerConsignmentSaleInput {
  items: { inventory_item_id: string; quantity: number; unit_price?: number }[];
  note?: string | null;
}

export interface CustomerConsignmentReturnInput {
  items: { inventory_item_id: string; quantity: number }[];
  note?: string | null;
}

// ── Notifications ─────────────────────────────────────────────────────────────

export const NOTIFICATION_TYPES = ["MENTION", "NEW_ORDER", "ORDER_STATUS", "REPLY"] as const;
export type NotificationType = (typeof NOTIFICATION_TYPES)[number];

export interface Notification {
  id: string;
  type: NotificationType;
  title: string;
  body: string | null;
  link: string | null;
  is_read: boolean;
  created_at: string | null;
}

// ── Produce ───────────────────────────────────────────────────────────────────

/** Payload for POST /inventory-items/{id}/produce. */
export interface ProduceInput {
  display_quantity: number;
}