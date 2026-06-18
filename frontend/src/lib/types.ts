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

/** Billable application modules — mirrors App\Enums\Module. */
export const MODULES = [
  "dashboard",
  "inventory",
  "orders",
  "customers",
  "suppliers",
  "inflows",
  "costs",
  "cash_flow",
  "work_orders",
  "cellar",
  "vineyards",
  "production",
  "team",
  "settings",
  "ai_data_entry",
] as const;
export type Module = (typeof MODULES)[number];

/** Subscription access level — mirrors App\Enums\AccessLevel. */
export type AccessLevel = "full" | "read_only" | "blocked";

/** Computed subscription access for the active tenant (GET /auth/me). */
export interface TenantAccess {
  level: AccessLevel;
  status: string;
  trial_ends_at: string | null;
  current_period_end: string | null;
  grace_full_until: string | null;
  grace_readonly_until: string | null;
  days_remaining: number | null;
}

/** Returned by login / switch-tenant / me. The `token` is tenant-bound. */
export interface AuthSession {
  token: string | null;
  user: User;
  active_tenant_id: string | null;
  roles: string[];
  tenants: TenantMembership[];
  settings: OrganizationSettings | null;
  /** Module keys the active tenant's plan includes (empty when no tenant). */
  modules: string[];
  /** Subscription access state, or null when there's no active tenant. */
  access: TenantAccess | null;
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
  description: string | null;
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
  /** Lead image (first by sort order) for list thumbnails; null when none. */
  image_url?: string | null;
}

export interface InventoryQuery {
  search?: string;
  category?: string;
  is_active?: boolean;
  is_for_sale?: boolean;
  sellable?: boolean;
  page?: number;
}

/** Inventory categories — mirrors App\Enums\InventoryCategory. */
export const INVENTORY_CATEGORIES = ["FINISHED", "SEMI_FINISHED", "RAW_MATERIAL"] as const;
export type InventoryCategory = (typeof INVENTORY_CATEGORIES)[number];

/**
 * Common units of measure. The backend stores `unit` as a free string (max 50),
 * so this is a curated convenience list, not a hard enum — extend freely.
 */
export const INVENTORY_UNITS = ["bottle", "case", "liter", "ml", "kg", "gram", "unit"] as const;
export type InventoryUnit = (typeof INVENTORY_UNITS)[number];

/** Measure units for an item's unit size (e.g. 750 ml). Universal abbreviations. */
export const UNIT_SIZE_UNITS = ["ml", "cl", "l", "gr", "kg"] as const;
export type UnitSizeUnit = (typeof UNIT_SIZE_UNITS)[number];

/**
 * How an item is sold — mirrors App\Enums\SalesUnit. Determines the unit an
 * order line must use for the item (strict: bottles→bottles, cases→cases).
 */
export const SALES_UNITS = ["bottles", "cases"] as const;
export type SalesUnit = (typeof SALES_UNITS)[number];

/**
 * Payload for POST /inventory-items. Prices/costs are integer minor units.
 * On create, sales_unit, bottles_per_case and cost_per_unit are required by the
 * backend. (`pack_size` is a separate legacy column and is not edited here.)
 */
export interface InventoryItemInput {
  name: string;
  sku: string;
  category: InventoryCategory;
  unit: string;
  // Optional — COGS can be derived from the item's recipe instead.
  cost_per_unit?: number | null;
  // Only sent for packaged (bottle/case) items.
  sales_unit?: SalesUnit;
  bottles_per_case?: number;
  description?: string | null;
  group?: string | null;
  subcategory?: string | null;
  vintage?: string | null;
  unit_size?: string | null;
  min_stock?: number | null;
  default_price?: number | null;
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
export interface InventoryExitMetrics {
  units_exited: number;
  cost_of_exits: Money | null;
  revenue_realized: Money | null;
  mean_margin_percent?: string | null;
  mean_price?: Money | null;
  off_target_percent?: string | null;
  velocity_per_day?: string;
}

/** A row in the inventory-check audit history. */
export interface InventoryCheckSummary {
  id: string;
  reference: string;
  performed_by: string | null;
  items_counted: number;
  items_adjusted: number;
  net_difference: string;
  created_at: string | null;
}

export interface InventoryCheckLine {
  name: string;
  sku: string;
  system_count: string;
  physical_count: string;
  difference: string;
}

export interface InventoryCheckDetail extends InventoryCheckSummary {
  lines: InventoryCheckLine[];
}

/** Payload for POST /inventory-items/check. */
export interface InventoryCheckInput {
  items: { item_id: string; physical_count: number; system_stock?: number }[];
}

export interface SpendSummary {
  units_exited: number;
  movements: number;
  cost_value: Money;
  revenue: Money;
  distinct_skus: number;
}

export interface SpendProduct {
  id: string;
  name: string;
  sku: string;
  vintage: number | null;
  group: string | null;
  subcategory: string | null;
  on_hand: number;
  units_exited: number;
  prev_units_exited: number;
  velocity_per_day: string;
  days_left: number | null;
  cost_of_exits: Money | null;
  revenue: Money | null;
  daily: number[];
}

/** GET /inventory-items/spend — warehouse-exit spend over a date range. */
export interface InventorySpend {
  period: { from: string; to: string; days: number };
  previous_period: { from: string; to: string; days: number };
  summary: SpendSummary;
  previous: SpendSummary;
  daily: { date: string; units: number }[];
  per_product: SpendProduct[];
}

export interface InventoryAnalytics {
  summary: {
    total_active: number;
    low_stock: number;
    out_of_stock: number;
    for_sale: number;
    by_category: { FINISHED: number; SEMI_FINISHED: number; RAW_MATERIAL: number };
    priced_count: number;
    sale_value: Money;
    production_value: Money;
    margin_percent: string;
  };
  portfolio_exits: {
    period_days: number;
    external: InventoryExitMetrics;
    blended: InventoryExitMetrics;
  };
  movements_12m: { month: string; in: number; out: number }[];
  top_products: { name: string; value: number }[];
  by_group: { group: string | null; count: number }[];
  stock_levels: { name: string; stock: string }[];
  value: {
    total: number;
    currency: string;
    categories: { category: string; value: number }[];
  };
  low_stock: { below: StockWatchItem[]; approaching: StockWatchItem[] };
}

// ── Customers & pricing ───────────────────────────────────────────────────────

/** Payload for POST /customers/merge[/preview]. */
export interface MergeCustomersInput {
  winner_id: string;
  loser_ids: string[];
}

export interface MergePreviewLoser {
  id: string;
  company_name: string;
  orders: number;
  price_reassign: number;
  price_drop: number;
  override_reassign: number;
  override_drop: number;
}

/** Result of merge preview/apply: what moves and what gets dropped. */
export interface MergePreview {
  applied: boolean;
  winner: { id: string; company_name: string };
  losers: MergePreviewLoser[];
  totals: {
    orders: number;
    price_reassign: number;
    price_drop: number;
    override_reassign: number;
    override_drop: number;
    losers_deleted: number;
  };
}

export interface CustomerAnalyticsRow {
  customer_id: string;
  company_name: string;
  contact_name: string | null;
  revenue_12m: Money;
  revenue_all_time: Money;
  order_count_12m: number;
  avg_order_value: Money;
  last_order_date: string | null;
  days_since_last_order: number | null;
  median_gap_days: number | null;
  expected_next_order_date: string | null;
}

/** GET /customers/analytics — tenant-wide customer analytics. */
export interface CustomerAnalytics {
  summary: {
    active_customers: number;
    revenue_12m: Money;
    top_customer: {
      id: string;
      company_name: string;
      contact_name: string | null;
      revenue_12m: Money;
    } | null;
  };
  customers: CustomerAnalyticsRow[];
}

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

/** Customer sales channel — mirrors App\Enums\CustomerType. */
export const CUSTOMER_TYPES = ["WHOLESALE", "RETAIL", "AGENCY", "SHIPSHOP", "OTHER"] as const;
export type CustomerType = (typeof CUSTOMER_TYPES)[number];

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
  customer_type: CustomerType | null;
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

/** GET /customers/{id}/order-analytics — forward-looking revenue metrics. */
export interface CustomerOrderAnalytics {
  total_revenue: Money;
  this_year: Money;
  last_year: Money;
  last_order_date: string | null;
  yoy_growth_percent: string;
  annual_projection: Money;
  expected_next_order_date: string | null;
  next_quarter_projection: Money;
  /** Per-month forecast for the next 3 months: same month last year × (1 + YoY). */
  expected_next_3m: { month: string; last_year: Money; expected: Money }[];
}

/** A customer's negotiated per-product price (overrides rebate). */
export interface CustomerCustomPrice {
  inventory_item_id: string;
  name: string | null;
  sku: string | null;
  price: Money;
}

/** GET /inventory-items/{id}/tier-prices — an item's tier price book. */
export interface ItemTierPrice {
  pricing_tier_id: string;
  tier_name: string | null;
  rebate_percent: string | null;
  price: Money;
}

/** GET /inventory-items/{id}/customer-prices — an item's per-customer overrides. */
export interface ItemCustomerPrice {
  customer_id: string;
  company_name: string | null;
  price: Money;
}

/** The numeric enology measurements on a bottle analysis (all optional). */
export const BOTTLE_ANALYSIS_FIELDS = [
  "ph",
  "total_acidity",
  "volatile_acidity",
  "alcohol",
  "residual_sugar",
  "free_so2",
  "total_so2",
  "temperature",
  "density",
  "tpi",
] as const;
export type BottleAnalysisField = (typeof BOTTLE_ANALYSIS_FIELDS)[number];

/** GET /inventory-items/{id}/bottle-analyses — lab analyses for an item. */
export interface BottleAnalysis extends Record<BottleAnalysisField, number | null> {
  id: string;
  analyzed_on: string;
  note: string | null;
}

/** Payload for POST /inventory-items/{id}/bottle-analyses. */
export interface BottleAnalysisInput extends Partial<Record<BottleAnalysisField, number | null>> {
  analyzed_on: string;
  note?: string | null;
}

/** GET /public/{token}/catalog — the customer-facing self-service catalog. */
export interface PublicCatalog {
  customer: { company_name: string; hide_prices: boolean; allow_single_bottle: boolean };
  products: PublicCatalogProduct[];
}

export interface PublicCatalogProduct {
  id: string;
  name: string;
  sku: string;
  vintage: string | null;
  unit: SalesUnit;
  bottles_per_case: number;
  price?: Money;
}

/** POST /public/{token}/orders — a self-service order. */
export interface PublicOrderInput {
  items: { inventory_item_id: string; quantity: number; unit_type?: SalesUnit }[];
  notes?: string | null;
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
  customer_type?: CustomerType | null;
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

/** A current-period amount and the prior period to compare it against (minor units). */
export interface RevenueComparison {
  current: number;
  previous: number | null;
}

/** GET /dashboard — aggregated summary. Money fields are integer minor units. */
export interface DashboardSummary {
  range: string;
  currency: string;
  /** Always-on revenue cards: today / month-to-date / year-to-date / all-time total. */
  revenue_summary: {
    today: RevenueComparison;
    mtd: RevenueComparison;
    ytd: RevenueComparison;
    total: RevenueComparison;
  };
  /** Selected-period revenue split by customer channel (minor units). */
  revenue_by_channel: {
    wholesale: number;
    retail: number;
    agency: number;
    shipshop: number;
    other: number;
    total: number;
  };
  stats: {
    total_orders: number;
    customers: number;
    revenue: number;
    low_stock: number;
    outstanding_ar: number;
    tasks_overdue: number;
  };
  orders: SeriesPoint[];
  revenue: SeriesPoint[];
  order_status: { key: string; value: number }[];
  top_products: { name: string; value: number }[];
  stock_watch: StockWatchItem[];
  recent_orders: {
    id: string;
    order_number: string;
    customer: string;
    created_by: string | null;
    items: { name: string | null; quantity: number; unit_type: string }[];
    total: number;
    status: string;
    date: string;
  }[];
}

/** One flagged account on the reorder radar (overdue vs. its usual cadence). */
export interface ReorderRadarRow {
  customer_id: string;
  company_name: string;
  order_count: number;
  last_order_date: string;
  days_since_last: number;
  median_gap_days: number;
  overdue_ratio: number;
  status: "due" | "overdue" | "at_risk";
  avg_order_value: Money;
  urgency: number;
}

/** GET /customers/reorder-radar — accounts overdue to reorder, ranked by urgency. */
export interface ReorderRadar {
  rows: ReorderRadarRow[];
  counts: { due: number; overdue: number; at_risk: number };
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

/** Period windows for the per-item Stock tab analytics. */
export const STOCK_PERIODS = ["today", "mtd", "ytd", "30d", "90d"] as const;
export type StockPeriod = (typeof STOCK_PERIODS)[number];

/** GET /inventory-items/{id}/stock-analytics — per-item stock dashboard. */
export interface StockAnalytics {
  period: string;
  current: {
    stock_bottles: number;
    unit: string;
    bottles_per_case: number;
    min_stock_bottles: number | null;
    cost_per_bottle: Money | null;
    selling_per_bottle: Money | null;
  };
  realized: {
    mean_price: Money | null;
    rebate_percent: string | null;
    rebate_amount: Money | null;
    margin_percent: string | null;
    margin_amount: Money | null;
    sales_value: Money;
    bottles_sold: number;
  };
  exits: {
    bottles_exited: number;
    cost_of_exits: Money | null;
    revenue_realized: Money | null;
    mean_margin_percent: string | null;
    velocity_per_day: string;
    days_of_stock_left: number | null;
  };
  channels: { channel: string; bottles: number }[];
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
  input_group: string | null;
  input_stock: string | null;
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

/** GET /inventory-items/{id}/documents — an attached file with a read URL. */
export interface InventoryDocument {
  id: string;
  name: string;
  content_type: string;
  size_bytes: number;
  url: string;
}

/** Payload for POST /inventory-items/{id}/documents (after the object is uploaded). */
export interface AttachDocumentInput {
  key: string;
  name: string;
  content_type: string;
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

/** An order line's unit is the same bottles/cases domain as SalesUnit. */
export type OrderItemUnit = SalesUnit;

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
  deduct_stock?: boolean;
  is_consignment?: boolean;
  shipping_cost?: number | null;
  shipping_paid_by_us?: boolean;
  items: OrderItemInput[];
}

export interface OrderQuery {
  status?: OrderStatus;
  search?: string;
  hide_shipped?: boolean;
  customer_id?: string;
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

export const NOTIFICATION_TYPES = [
  "MENTION",
  "NEW_ORDER",
  "ORDER_STATUS",
  "REPLY",
  "ANNOUNCEMENT",
  "AI_IMPORT_READY",
  "AI_IMPORT_FAILED",
] as const;
export type NotificationType = (typeof NOTIFICATION_TYPES)[number];

export interface Notification {
  id: string;
  type: NotificationType;
  title: string;
  body: string | null;
  /** Route params for the client-side resolver (e.g. { order_id }). Path-free. */
  data: Record<string, string> | null;
  is_read: boolean;
  created_at: string | null;
}

// ── Produce ───────────────────────────────────────────────────────────────────

/** Payload for POST /inventory-items/{id}/produce. */
export interface ProduceInput {
  display_quantity: number;
}

// ── Finance: inflows / accounts-receivable (Phase 6) ──────────────────────────

export const INFLOW_STATUSES = ["PENDING", "RECEIVED"] as const;
export type InflowStatus = (typeof INFLOW_STATUSES)[number];

/** A money-in record (payment / A/R). Mirrors InflowData. */
export interface Inflow {
  id: string;
  customer_id: string | null;
  order_id: string | null;
  order_number?: string | null;
  changes_count?: number | null;
  date: string;
  amount: Money;
  status: InflowStatus;
  is_credit_note: boolean;
  category: string | null;
  reference: string | null;
  payment_method: string | null;
  notes: string | null;
  due_date: string | null;
  received_at: string | null;
  created_at: string | null;
}

/** Cash-in analytics for a period. Mirrors InflowAnalyticsQuery. */
export interface InflowAnalytics {
  period: { from: string; to: string };
  invoiced: { total: Money; count: number };
  collected: { total: Money; count: number };
  pending: { total: Money; count: number };
  net_cash_flow: { net: Money; inflows: Money; costs: Money };
  avg_days_to_collect: { days: number | null; count: number };
  avg_inflow: { avg: Money };
  by_category: { name: string; total: Money }[];
  by_customer: { customer_id: string; company_name: string | null; total: Money }[];
  over_time: { month: string; total: Money }[];
  cash_flow: { month: string; inflows: Money; costs: Money; net: Money }[];
}

/** A single edited field within an inflow change-history entry. */
export interface InflowFieldChange {
  field: string;
  old: string | number | boolean | null;
  new: string | number | boolean | null;
}

/** One audited edit of an inflow (a set of field diffs). Mirrors InflowChange. */
export interface InflowChange {
  id: string;
  changes: InflowFieldChange[];
  changed_by: string | null;
  created_at: string | null;
}

export interface OrderPaymentSummary {
  amount_paid: Money;
  balance_due: Money;
  status: "UNPAID" | "PARTIAL" | "PAID";
}

/** GET /orders/{id}/payments response. */
export interface OrderPayments {
  summary: OrderPaymentSummary;
  payments: Inflow[];
}

/** POST /orders/{id}/payments — amount is integer minor units. */
export interface RecordPaymentInput {
  amount: number;
  date?: string;
  status?: InflowStatus;
  is_credit_note?: boolean;
  payment_method?: string | null;
  reference?: string | null;
  notes?: string | null;
}

/** POST/PATCH /inflows — `amount` is integer minor units. Mirrors StoreInflowRequest. */
export interface InflowInput {
  amount: number;
  date?: string;
  customer_id?: string | null;
  order_id?: string | null;
  status?: InflowStatus;
  is_credit_note?: boolean;
  category?: string | null;
  reference?: string | null;
  payment_method?: string | null;
  notes?: string | null;
  due_date?: string | null;
}

/** GET /inflows filters. Mirrors ListInflowsQuery. */
export interface InflowQuery {
  status?: InflowStatus;
  customer_id?: string;
  order_id?: string;
  search?: string;
  page?: number;
}

/** GET /inflows/aging. Bucket keys start with digits → quoted. */
export interface ArAging {
  total_outstanding: Money;
  buckets: { current: Money; "31_60": Money; "61_90": Money; "90_plus": Money };
  by_customer: {
    customer_id: string;
    company_name: string | null;
    orders: number;
    outstanding: Money;
  }[];
}

// ── Costs / expenses (Phase 6) ────────────────────────────────────────────────

export const COST_STATUSES = ["PENDING", "APPROVED", "PAID"] as const;
export type CostStatus = (typeof COST_STATUSES)[number];

/** Suggested payment methods for a cost (stored as free text on the backend). */
export const PAYMENT_METHODS = ["cash", "bank_transfer", "card", "other"] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export interface CostItem {
  id: string;
  inventory_item_id: string | null;
  description: string;
  quantity: string | number;
  unit_price: Money;
  total: Money;
  category: string | null;
}

/** Mirrors CostData. */
export interface Cost {
  id: string;
  date: string;
  total_amount: Money;
  vat_amount: Money | null;
  category: string;
  description: string | null;
  reference: string | null;
  status: CostStatus;
  payment_method: string | null;
  notes: string | null;
  paid_at: string | null;
  due_date: string | null;
  supplier: { id: string; company_name: string } | null;
  items?: CostItem[];
  attachments?: { id: string; filename: string; content_type: string; size_bytes: number }[];
}

/** POST /costs — money fields are integer minor units. */
export interface CostInput {
  total_amount: number;
  category: string;
  date?: string;
  vat_amount?: number | null;
  description?: string | null;
  reference?: string | null;
  status?: CostStatus;
  payment_method?: string | null;
  notes?: string | null;
  due_date?: string | null;
  supplier_id?: string | null;
  items?: {
    description: string;
    unit_price: number;
    quantity?: number;
    category?: string | null;
    inventory_item_id?: string | null;
  }[];
}

/** Cost tab groups, driven by the reserved Invoice / Payment categories. */
export type CostGroup = "invoices" | "payments" | "others";

export interface CostQuery {
  search?: string;
  category?: string;
  status?: CostStatus;
  supplier_id?: string;
  group?: CostGroup;
  date_from?: string;
  date_to?: string;
  page?: number;
}

/** Tab counts for All / Invoices / Payments / Others. */
export interface CostGroupCounts {
  all: number;
  invoices: number;
  payments: number;
  others: number;
}

/** GET /costs/analytics. */
export interface CostAnalytics {
  period: { from: string; to: string };
  total_spend: Money;
  unpaid: Money;
  invoiced: { total: Money; count: number };
  paid: { total: Money; count: number };
  unpaid_invoices: { total: Money; count: number };
  avg_invoice: { avg: Money; max: Money };
  avg_days_to_pay: { days: number | null; count: number };
  gross_margin: { percent: string | null; revenue: Money };
  by_status: { status: string; count: number; total: Money }[];
  by_category: { name: string; total: Money }[];
  by_supplier: { supplier_id: string; company_name: string | null; total: Money }[];
  over_time: { month: string; total: Money }[];
  yoy: {
    current_year: number;
    previous_year: number;
    months: { month: number; current: Money; previous: Money }[];
  };
  top_costs: { id: string; date: string; category: string; supplier_name: string | null; total: Money }[];
  profit_loss: { month: string; revenue: Money; costs: Money; profit: Money }[];
}

// ── Suppliers + price lists (Phase 6) ─────────────────────────────────────────

export interface SupplierPriceItem {
  id: string;
  inventory_item_id: string | null;
  description: string;
  unit_price: Money;
  unit: string | null;
  notes: string | null;
  last_updated: string | null;
}

/** Payload for POST /suppliers/merge[/preview]. */
export interface MergeSuppliersInput {
  winner_id: string;
  loser_ids: string[];
}

export interface SupplierMergeLoser {
  id: string;
  company_name: string;
  orders: number;
  costs: number;
  price_reassign: number;
  price_drop: number;
}

/** Result of a supplier merge preview/apply: what moves and what gets dropped. */
export interface SupplierMergePreview {
  applied: boolean;
  winner: { id: string; company_name: string };
  losers: SupplierMergeLoser[];
  totals: {
    orders: number;
    costs: number;
    price_reassign: number;
    price_drop: number;
    losers_deleted: number;
  };
}

/** Supplier summary cards. Cost figures present only with finance visibility. */
export interface SupplierStats {
  price_items: number;
  cost_entries?: number;
  total_costs?: Money;
}

/** An audited cost change on a supplier price-list line. */
export interface SupplierPriceChange {
  id: string;
  description: string;
  unit: string | null;
  old_price: Money | null;
  new_price: Money;
  created_at: string | null;
}

/** Mirrors SupplierData. */
export interface Supplier {
  id: string;
  company_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  country: string | null;
  tax_id: string | null;
  bank_account: string | null;
  payment_terms: string | null;
  notes: string | null;
  is_active: boolean;
  exclude_from_stats: boolean;
  has_portal_token?: boolean;
  price_items_count: number | null;
  price_changes_count?: number | null;
  price_items?: SupplierPriceItem[];
}

/** Result of a bulk price-list import/upsert. */
export interface PriceImportResult {
  added: number;
  updated: number;
  total: number;
}

/** GET /public/supplier/{token} — the public supplier portal payload. */
export interface SupplierPortal {
  supplier: { company_name: string; contact_name: string | null };
  orders: SupplierOrder[];
  price_items: { id: string; description: string; unit_price: Money; unit: string | null }[];
}

export interface SupplierInput {
  company_name: string;
  contact_name?: string | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  city?: string | null;
  country?: string | null;
  tax_id?: string | null;
  bank_account?: string | null;
  payment_terms?: string | null;
  notes?: string | null;
  is_active?: boolean;
  exclude_from_stats?: boolean;
}

export interface SupplierQuery {
  search?: string;
  is_active?: boolean;
  page?: number;
}

/** POST /suppliers/{id}/price-items — unit_price is integer minor units. */
export interface PriceItemInput {
  description: string;
  unit_price: number;
  unit?: string | null;
  notes?: string | null;
  inventory_item_id?: string | null;
}

// ── Purchase orders (Phase 6) ─────────────────────────────────────────────────

export const SUPPLIER_ORDER_STATUSES = ["DRAFT", "SENT", "CONFIRMED", "RECEIVED", "CANCELLED"] as const;
export type SupplierOrderStatus = (typeof SUPPLIER_ORDER_STATUSES)[number];

export interface SupplierOrderItem {
  id: string;
  inventory_item_id: string | null;
  description: string;
  quantity: string | number;
  unit: string | null;
  unit_price: Money;
  total: Money;
}

/** Mirrors SupplierOrderData. */
export interface SupplierOrder {
  id: string;
  order_number: string;
  status: SupplierOrderStatus;
  total_amount: Money;
  notes: string | null;
  sent_at: string | null;
  expected_at: string | null;
  received_at: string | null;
  supplier: { id: string; company_name: string } | null;
  items?: SupplierOrderItem[];
}

/** POST /supplier-orders — quantity/unit_price are numbers (minor units for price). */
export interface SupplierOrderInput {
  supplier_id: string;
  expected_at?: string | null;
  notes?: string | null;
  items: {
    description: string;
    quantity: number;
    unit?: string | null;
    unit_price: number;
    inventory_item_id?: string | null;
  }[];
}

export interface SupplierOrderQuery {
  status?: SupplierOrderStatus;
  supplier_id?: string;
}

// ── Cash flow (Phase 6) ───────────────────────────────────────────────────────

export interface CashFlowMonth {
  month: string;
  revenue: Money;
  costs: Money;
  net: Money;
  is_projection: boolean;
}

/** GET /cash-flow. */
export interface CashFlow {
  currency: string;
  historical: CashFlowMonth[];
  forecast: CashFlowMonth[];
  pending: {
    receivable: Money;
    receivable_count: number;
    payable: Money;
    payable_count: number;
    net: Money;
  };
  summary: {
    avg_monthly_revenue: Money;
    avg_monthly_costs: Money;
    avg_monthly_net: Money;
    revenue_growth_percent: string;
  };
}

// ── Tasks / work orders (Phase 6) ─────────────────────────────────────────────

export const TASK_STATUSES = ["TODO", "IN_PROGRESS", "DONE"] as const;
export type TaskStatus = (typeof TASK_STATUSES)[number];
export const TASK_PRIORITIES = ["LOW", "MEDIUM", "HIGH"] as const;
export type TaskPriority = (typeof TASK_PRIORITIES)[number];

/** Work-order category — mirrors App\Enums\WorkOrderCategory. */
export const WORK_ORDER_CATEGORIES = [
  "CELLAR",
  "VINEYARD",
  "MAINTENANCE",
  "ADMIN",
  "DELIVERY",
  "EVENT",
  "OTHER",
] as const;
export type WorkOrderCategory = (typeof WORK_ORDER_CATEGORIES)[number];

/** Mirrors WorkOrderData. */
export interface WorkOrder {
  id: string;
  title: string;
  description: string | null;
  category: WorkOrderCategory | null;
  priority: TaskPriority;
  status: TaskStatus;
  start_date: string | null;
  due_date: string | null;
  completed_at: string | null;
  sort_order: number;
  assignee: { id: string; name: string } | null;
}

export interface WorkOrderInput {
  title: string;
  description?: string | null;
  category?: WorkOrderCategory | null;
  priority?: TaskPriority;
  status?: TaskStatus;
  start_date?: string | null;
  due_date?: string | null;
  sort_order?: number;
  assignee_id?: string | null;
}

export interface WorkOrderQuery {
  status?: TaskStatus;
  assignee_id?: string;
  category?: WorkOrderCategory;
  search?: string;
  due_from?: string;
  due_to?: string;
}

export interface WorkOrderStats {
  todo: number;
  in_progress: number;
  done: number;
  overdue: number;
}

// ── AI data entry ───────────────────────────────────────────────────────────

export const AI_IMPORT_TYPES = [
  "bank_statement",
  "invoice",
  "inventory_list",
  "supplier_list",
  "cash_inflow",
] as const;
export type AiImportType = (typeof AI_IMPORT_TYPES)[number];

export const AI_IMPORT_STATUSES = [
  "uploaded",
  "processing",
  "ready",
  "partially_committed",
  "committed",
  "failed",
] as const;
export type AiImportStatus = (typeof AI_IMPORT_STATUSES)[number];

export const AI_LINE_STATUSES = ["pending", "approved", "edited", "rejected", "committed"] as const;
export type AiLineStatus = (typeof AI_LINE_STATUSES)[number];

export type AiTargetType = "cost" | "inflow" | "order" | "inventory_item" | "supplier";

export interface AiImportLine {
  id: string;
  index: number;
  target_type: AiTargetType;
  target_label: string;
  category: string | null;
  confidence: number | null;
  status: AiLineStatus;
  payload: Record<string, unknown>;
  edited_payload: Record<string, unknown> | null;
  effective_payload: Record<string, unknown>;
  committed_id: string | null;
}

export interface AiImport {
  id: string;
  type: AiImportType;
  type_label: string;
  status: AiImportStatus;
  status_label: string;
  source_filename: string | null;
  source_mime: string | null;
  provider: string | null;
  model: string | null;
  prompt_tokens: number;
  completion_tokens: number;
  error: string | null;
  created_at: string | null;
  lines_total?: number;
  lines_pending?: number;
  lines_committed?: number;
  lines?: AiImportLine[];
}

export interface CreateAiImportInput {
  type: AiImportType;
  object_key: string;
  filename: string;
  mime?: string | null;
}

export interface UpdateAiImportLineInput {
  status: AiLineStatus;
  edited_payload?: Record<string, unknown> | null;
}

export interface CommitAiImportResult {
  data: AiImport;
  meta: { committed: number; failed: number; errors: Record<string, string> };
}

/* ---------------------------------------------------------------------------
 * Cellar — mirrors App\DataTransferObjects\{VesselData,WineLotData,
 * EnologicalProductData,FermentationTemplateData} and the cellar enums.
 * ------------------------------------------------------------------------- */

export const VESSEL_TYPES = ["BARREL", "BARRIQUE", "TANK", "VAT", "AMPHORA"] as const;
export type VesselType = (typeof VESSEL_TYPES)[number];

export const VESSEL_STATUSES = ["AVAILABLE", "IN_USE", "MAINTENANCE", "RETIRED"] as const;
export type VesselStatus = (typeof VESSEL_STATUSES)[number];

export const WINE_LOT_STATUSES = ["FERMENTING", "AGING", "READY", "BOTTLED", "BLENDED"] as const;
export type WineLotStatus = (typeof WINE_LOT_STATUSES)[number];

export const WINE_TYPES = ["RED", "WHITE", "ROSE", "ORANGE", "SPARKLING", "DESSERT"] as const;
export type WineType = (typeof WINE_TYPES)[number];

export const CELLAR_TRANSFER_TYPES = ["RACK", "BLEND", "SPLIT"] as const;
export type CellarTransferType = (typeof CELLAR_TRANSFER_TYPES)[number];

/** The 14 measurement columns on a cellar analysis. */
export const ANALYSIS_PARAMETERS = [
  "ph", "total_acidity", "volatile_acidity", "alcohol", "residual_sugar",
  "free_so2", "total_so2", "brix", "temperature", "density", "malic",
  "lactic", "tpi", "glucose_fructose",
] as const;
export type AnalysisParameter = (typeof ANALYSIS_PARAMETERS)[number];

export interface VesselLotSummary {
  vessel_lot_id: string;
  wine_lot_id: string;
  volume: string;
  lot: {
    id: string;
    lot_number: string;
    name: string;
    grape_variety: string;
    vintage: string;
    wine_type: WineType | null;
    free_so2: string | null;
    total_so2: string | null;
  } | null;
}

export interface Vessel {
  id: string;
  name: string;
  type: VesselType;
  material: string | null;
  capacity_liters: string;
  current_volume: string;
  location: string | null;
  status: VesselStatus;
  is_active: boolean;
  is_faulty: boolean;
  fault_note: string | null;
  room: string;
  position_x: number | null;
  position_y: number | null;
  map_width: number | null;
  map_height: number | null;
  rotation: number | null;
  notes: string | null;
  lots?: VesselLotSummary[];
}

export interface VesselInput {
  name: string;
  type: VesselType;
  capacity_liters: number;
  material?: string | null;
  location?: string | null;
  room?: string | null;
  is_faulty?: boolean;
  fault_note?: string | null;
  notes?: string | null;
}

export interface VesselLayoutUpdate {
  id: string;
  position_x?: number | null;
  position_y?: number | null;
  map_width?: number | null;
  map_height?: number | null;
  rotation?: number | null;
  room?: string | null;
}

export interface BulkCreateVesselsInput {
  prefix?: string;
  start_number?: number;
  count: number;
  type: VesselType;
  material?: string | null;
  capacity_liters: number;
  room?: string | null;
}

export interface WineLotGrapeRow {
  id: string;
  grape_variety: string;
  percentage: string | null;
  price_per_kg: Money | null;
  weight_kg: string | null;
}

export interface CellarAnalysis {
  id: string;
  vessel_id: string | null;
  date: string;
  note: string | null;
  // Each measurement parameter is a nullable decimal string.
  ph: string | null;
  total_acidity: string | null;
  volatile_acidity: string | null;
  alcohol: string | null;
  residual_sugar: string | null;
  free_so2: string | null;
  total_so2: string | null;
  brix: string | null;
  temperature: string | null;
  density: string | null;
  malic: string | null;
  lactic: string | null;
  tpi: string | null;
  glucose_fructose: string | null;
}

export interface CellarAddition {
  id: string;
  name: string;
  category: string | null;
  quantity: string;
  unit: string;
  total_cost: Money | null;
  note: string | null;
  date: string | null;
}

export interface CellarProcessRow {
  id: string;
  kind: string;
  vessel_id: string | null;
  volume: string | null;
  note: string | null;
  date: string | null;
}

export interface TastingNote {
  id: string;
  date: string | null;
  appearance: string | null;
  nose: string | null;
  palate: string | null;
  overall: string | null;
  score: number | null;
  note: string | null;
}

export interface CellarTransferRow {
  id: string;
  type: CellarTransferType;
  direction: "in" | "out";
  from_lot_id: string;
  to_lot_id: string;
  volume_liters: string;
  note: string | null;
  date: string | null;
}

export interface BottlingRow {
  id: string;
  bottle_count: number;
  bottle_volume_ml: number;
  volume_used: string;
  inventory_item_id: string | null;
  note: string | null;
  date: string | null;
}

export interface LotCostBreakdown {
  total: number;
  additions: number;
  grape: number;
  per_liter: number;
  per_bottle_750: number;
}

export interface WineLotVesselRow {
  vessel_lot_id: string;
  vessel_id: string;
  vessel_name: string | null;
  volume: string;
}

export interface WineLot {
  id: string;
  lot_number: string;
  name: string;
  grape_variety: string;
  vintage: string;
  vineyard: string | null;
  wine_type: WineType | null;
  initial_volume: string;
  current_volume: string;
  status: WineLotStatus;
  fermentation_template_id: string | null;
  grape_cost: Money | null;
  grape_price_per_kg: Money | null;
  harvest_weight_kg: string | null;
  notes: string | null;
  // List summaries (mirrors of the latest analysis/addition).
  latest_analysis?: {
    date: string;
    ph: string | null;
    alcohol: string | null;
    free_so2: string | null;
    total_so2: string | null;
  } | null;
  latest_addition?: { name: string; quantity: string; unit: string; date: string | null } | null;
  // Present on the detail endpoint (withDetail).
  cost_breakdown?: LotCostBreakdown | null;
  grapes?: WineLotGrapeRow[];
  vessels?: WineLotVesselRow[];
  analyses?: CellarAnalysis[];
  additions?: CellarAddition[];
  processes?: CellarProcessRow[];
  tasting_notes?: TastingNote[];
  transfers?: CellarTransferRow[];
  bottlings?: BottlingRow[];
}

export interface WineLotInput {
  name: string;
  grape_variety: string;
  vintage: string;
  initial_volume: number;
  wine_type?: WineType | null;
  vineyard?: string | null;
  status?: WineLotStatus;
  grape_price_per_kg?: number | null;
  harvest_weight_kg?: number | null;
  notes?: string | null;
  vessel_id?: string | null;
  grapes?: Array<{ grape_variety: string; percentage?: number | null; price_per_kg?: number | null; weight_kg?: number | null }>;
}

export interface WineLotQuery {
  status?: WineLotStatus;
  search?: string;
  exclude_bottled?: boolean;
  page?: number;
  per_page?: number;
}

export interface AnalysisTrendPoint {
  id: string;
  date: string;
  vessel_id: string | null;
  vessel_name: string | null;
  [param: string]: string | number | null;
}

export interface AnalysisInput {
  vessel_id?: string | null;
  date?: string | null;
  note?: string | null;
  ph?: number | null;
  total_acidity?: number | null;
  volatile_acidity?: number | null;
  alcohol?: number | null;
  residual_sugar?: number | null;
  free_so2?: number | null;
  total_so2?: number | null;
  brix?: number | null;
  temperature?: number | null;
  density?: number | null;
  malic?: number | null;
  lactic?: number | null;
  tpi?: number | null;
  glucose_fructose?: number | null;
}

export interface AdditionInput {
  name: string;
  quantity: number;
  unit: string;
  category?: string | null;
  cost_per_unit?: number | null;
  total_cost?: number | null;
  note?: string | null;
  enological_product_id?: string | null;
}

export interface ProcessInput {
  kind: string;
  date?: string | null;
  volume?: number | null;
  note?: string | null;
  vessel_id?: string | null;
}

export interface TastingNoteInput {
  date?: string | null;
  appearance?: string | null;
  nose?: string | null;
  palate?: string | null;
  overall?: string | null;
  score?: number | null;
  note?: string | null;
  vessel_id?: string | null;
}

export interface TransferInput {
  type: CellarTransferType;
  volume_liters: number;
  to_lot_id: string;
  from_vessel_id?: string | null;
  to_vessel_id?: string | null;
  note?: string | null;
}

export interface BottlingInput {
  bottle_count: number;
  bottle_volume_ml?: number;
  date?: string | null;
  note?: string | null;
  inventory_item_id?: string | null;
}

export interface EnologicalProduct {
  id: string;
  name: string;
  category: string;
  unit: string;
  current_stock: string;
  min_stock: string | null;
  cost_per_unit: Money | null;
  manufacturer: string | null;
  packaging_size: string | null;
  so2_uplift_per_unit: string | null;
  supplier_id: string | null;
  is_active: boolean;
  notes: string | null;
}

export interface EnologicalProductInput {
  name: string;
  category: string;
  unit: string;
  current_stock?: number;
  min_stock?: number | null;
  cost_per_unit?: number | null;
  manufacturer?: string | null;
  packaging_size?: string | null;
  so2_uplift_per_unit?: number | null;
  supplier_id?: string | null;
  notes?: string | null;
}

export interface FermentationStageAction {
  id?: string;
  type?: string;
  description?: string;
}

export interface FermentationStage {
  id?: string;
  name: string;
  dayStart: number;
  dayEnd: number;
  tempMin?: number | null;
  tempMax?: number | null;
  actions?: FermentationStageAction[];
}

export interface FermentationTemplate {
  id: string;
  name: string;
  wine_type: WineType | null;
  yeast_strain: string | null;
  target_temp_min: string | null;
  target_temp_max: string | null;
  punchdown_schedule: string | null;
  maceration: string | null;
  nutrients: string | null;
  mlf: boolean;
  description: string | null;
  estimated_duration: number | null;
  stages: FermentationStage[];
  is_active: boolean;
}

export interface FermentationTemplateInput {
  name: string;
  wine_type?: WineType | null;
  yeast_strain?: string | null;
  target_temp_min?: number | null;
  target_temp_max?: number | null;
  punchdown_schedule?: string | null;
  maceration?: string | null;
  nutrients?: string | null;
  mlf?: boolean;
  description?: string | null;
  estimated_duration?: number | null;
  stages?: FermentationStage[];
  is_active?: boolean;
}

export interface FermentationAlert {
  type: string;
  severity: "critical" | "high" | "medium" | "info";
  lot_id: string;
  lot_name: string;
  message: string;
  suggestion: string;
}

export interface ProtocolGenerateResult {
  created: number;
  skipped: number;
  day: number;
  active_stages: string[];
}

export interface CellarCostRow {
  id: string;
  lot_number: string;
  name: string;
  vintage: string;
  current_volume: string;
  cost: LotCostBreakdown;
}

export interface CellarAnalytics {
  by_vintage: { label: string; volume: number }[];
  by_type: { label: string; volume: number }[];
  by_grape: { label: string; volume: number }[];
  pipeline: { status: string; count: number; volume: number }[];
}

export interface TastingReportRow {
  id: string;
  title: string | null;
  date: string;
  note: string | null;
  notes_count: number;
}

export interface TastingReportInput {
  title?: string | null;
  date?: string | null;
  note?: string | null;
}

export interface BulkAdditionsInput {
  name: string;
  unit: string;
  category?: string | null;
  cost_per_unit?: number | null;
  enological_product_id?: string | null;
  note?: string | null;
  lots: { wine_lot_id: string; quantity: number }[];
}

export interface BlendInput {
  name: string;
  vintage?: string | null;
  wine_type?: WineType | null;
  destination_vessel_id: string;
  sources: { vessel_lot_id: string; volume: number }[];
}

/* ---------------------------------------------------------------------------
 * Vineyards
 * ------------------------------------------------------------------------- */

export const PARCEL_OWNERSHIPS = ["OWN", "COOPERANT"] as const;
export type ParcelOwnership = (typeof PARCEL_OWNERSHIPS)[number];

export const PHENOLOGY_STAGES = ["BUD_BREAK", "FLOWERING", "FRUIT_SET", "VERAISON", "HARVEST_READY"] as const;
export type PhenologyStage = (typeof PHENOLOGY_STAGES)[number];

export const APPLICATION_TYPES = ["SPRAY", "FERTILIZER", "HERBICIDE", "OTHER"] as const;
export type ApplicationType = (typeof APPLICATION_TYPES)[number];

export const HARVEST_ENTRY_STATUSES = ["PLANNED", "HARVESTED", "PROCESSED"] as const;
export type HarvestEntryStatus = (typeof HARVEST_ENTRY_STATUSES)[number];

export const GRAPE_CONTRACT_STATUSES = ["ACTIVE", "FULFILLED", "CANCELLED"] as const;
export type GrapeContractStatus = (typeof GRAPE_CONTRACT_STATUSES)[number];

export interface MaturitySampleRow {
  id: string;
  date: string;
  brix: string | null;
  ph: string | null;
  total_acidity: string | null;
  temperature: string | null;
  note: string | null;
}

export interface PhenologyLogRow {
  id: string;
  date: string;
  stage: PhenologyStage;
  progress_percent: string | null;
  note: string | null;
}

export interface CropEstimateRow {
  id: string;
  date: string;
  cluster_count: number;
  avg_cluster_weight: string;
  sample_vine_count: number;
  estimated_yield_kg: string;
  note: string | null;
}

export interface ApplicationRow {
  id: string;
  date: string;
  type: ApplicationType;
  product: string | null;
  dosage: string | null;
  phi_days: number | null;
  phi_end_date: string | null;
  note: string | null;
}

export interface Parcel {
  id: string;
  name: string;
  grape_variety: string;
  area_hectares: string | null;
  location: string | null;
  elevation: number | null;
  soil_type: string | null;
  planting_year: number | null;
  row_spacing: string | null;
  vine_count: number | null;
  rootstock: string | null;
  training: string | null;
  orientation: string | null;
  slope: string | null;
  latitude: string | null;
  longitude: string | null;
  geo_polygon: unknown;
  ownership: ParcelOwnership;
  cooperant_supplier_id: string | null;
  is_active: boolean;
  notes: string | null;
  maturity_samples?: MaturitySampleRow[];
  phenology_logs?: PhenologyLogRow[];
  crop_estimates?: CropEstimateRow[];
  applications?: ApplicationRow[];
}

export interface ParcelInput {
  name: string;
  grape_variety: string;
  area_hectares?: number | null;
  location?: string | null;
  vine_count?: number | null;
  soil_type?: string | null;
  ownership?: ParcelOwnership;
  cooperant_supplier_id?: string | null;
  notes?: string | null;
}

export interface GrapeContract {
  id: string;
  supplier_id: string;
  parcel_id: string | null;
  season: string;
  status: GrapeContractStatus;
  grape_variety: string;
  estimated_kg: string;
  delivered_kg: string;
  price_per_kg: Money;
  min_brix: string | null;
  max_ph: string | null;
  delivery_window: string | null;
  payment_terms: string | null;
  notes: string | null;
}

export interface GrapeContractInput {
  supplier_id: string;
  parcel_id?: string | null;
  season: string;
  grape_variety: string;
  estimated_kg: number;
  price_per_kg: number;
  status?: GrapeContractStatus;
  notes?: string | null;
}

export interface GrowerPerformance {
  contracts: number;
  estimated_kg: number;
  delivered_kg: number;
  fulfillment_pct: number;
  reliability_pct: number;
}

export interface HarvestEntryRow {
  id: string;
  status: HarvestEntryStatus;
  source: string;
  grape_variety: string | null;
  parcel_id: string | null;
  parcel_name: string | null;
  contract_id: string | null;
  planned_vessel_id: string | null;
  wine_lot_id: string | null;
  planned_date: string | null;
  estimated_yield_kg: string | null;
  actual_date: string | null;
  actual_yield_kg: string | null;
  actual_volume_liters: string | null;
  brix: string | null;
  ph: string | null;
}

export interface HarvestPlan {
  id: string;
  name: string;
  season: string;
  status: string;
  yield_ratio: string;
  notes: string | null;
  entries_count: number;
  entries?: HarvestEntryRow[];
}

export interface HarvestPlanInput {
  name: string;
  season: string;
  yield_ratio?: number;
  notes?: string | null;
}

export interface HarvestEntryInput {
  parcel_id?: string | null;
  contract_id?: string | null;
  supplier_id?: string | null;
  planned_vessel_id?: string | null;
  grape_variety?: string | null;
  planned_date?: string | null;
  estimated_yield_kg?: number | null;
}

export interface RecordIntakeInput {
  actual_yield_kg: number;
  actual_volume_liters?: number | null;
  grape_price_per_kg?: number | null;
  grape_variety?: string | null;
  vessel_id?: string | null;
  existing_lot_id?: string | null;
  brix?: number | null;
  ph?: number | null;
}

export interface IntakeBooking {
  id: string;
  harvest_plan_id: string | null;
  supplier_id: string | null;
  date: string;
  time_slot: string | null;
  grape_variety: string | null;
  estimated_kg: string | null;
  grower_name: string | null;
  status: string;
  notes: string | null;
}

/* ----------------------------- Production -------------------------------- */

export const PLAN_UNITS = ["liters", "bottles", "cases"] as const;
export type PlanUnit = (typeof PLAN_UNITS)[number];

export const PRODUCTION_PLAN_STATUSES = ["DRAFT", "CONFIRMED", "CANCELLED"] as const;
export type ProductionPlanStatus = (typeof PRODUCTION_PLAN_STATUSES)[number];

export interface ProductionPlanRow {
  id: string;
  base_item_id: string;
  base_item_name: string | null;
  new_vintage: string | null;
  created_item_id: string | null;
  quantity: string;
  plan_unit: PlanUnit;
  sort_order: number;
}

export interface ProductionPlan {
  id: string;
  name: string;
  status: ProductionPlanStatus;
  confirmed_at: string | null;
  notes: string | null;
  rows_count: number;
  rows?: ProductionPlanRow[];
}

export interface ProductionPlanInput {
  name: string;
  notes?: string | null;
}

export interface ProductionPlanRowInput {
  base_item_id: string;
  quantity: number;
  plan_unit: PlanUnit;
  new_vintage?: string | null;
  sort_order?: number;
}

export interface ProductionPlanUpdate {
  name?: string;
  notes?: string | null;
  rows?: ProductionPlanRowInput[];
}

export interface ProductionCalcRow {
  row_id: string;
  base_item_id: string;
  name: string;
  quantity: string;
  plan_unit: PlanUnit;
  bottles: number;
  new_vintage: string | null;
  revenue: number;
  cost: number;
  margin_pct: number;
}

export interface ProductionCalcMaterial {
  name: string;
  unit: string;
  quantity: number;
  cost: number;
}

export interface ProductionCalculation {
  rows: ProductionCalcRow[];
  materials: ProductionCalcMaterial[];
  totals: { revenue: number; cost: number; margin_pct: number };
}

export interface VintageConflict {
  row_id: string;
  name: string;
  vintage: string | null;
  existing_id: string;
}
