/** Mirrors App\DataTransferObjects\InventoryItemData plus the presenter's image_url. */

/**
 * Mirrors App\Support\Money\Money::jsonSerialize().
 *
 * `minor` is the integer amount in minor units and is the ONLY field to compute
 * with. `formatted` is the server's major-unit string, kept for debugging; the
 * client formats from `minor` so the viewer's locale decides separators.
 */
export interface MoneyValue {
    minor: number;
    currency: string;
    formatted: string;
}

export interface InventoryItem {
    id: string;
    name: string;
    sku: string;
    category: string;
    description: string | null;
    group: string | null;
    subcategory: string | null;
    vintage: number | null;
    unit_size: string | null;
    unit: string;
    sales_unit: string | null;
    /** Decimal string — quantities are not JS numbers on the wire. */
    current_stock: string;
    min_stock: string | null;
    is_active: boolean;
    is_for_sale: boolean;
    hide_from_portal: boolean | null;
    sort_order: number | null;
    bottles_per_case: number | null;
    pack_size: number | null;
    base_product_id: string | null;
    is_auto_created: boolean | null;
    default_price: MoneyValue | null;
    cost_per_unit: MoneyValue | null;
    /** Signed read URL for the lead image, added by InventoryItemPresenter. */
    image_url: string | null;
}

/** Mirrors App\Queries\ItemAuditTrailQuery::get() — the Item — View drawer's Timeline section and Provenance line. */
export interface ItemAuditTrailEntry {
    id: string;
    action: string;
    actor_name: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

/** Mirrors App\Queries\ItemPricingQuery::tierPrices() — Product Detail's Pricing tab. */
export interface ItemTierPriceRow {
    pricing_tier_id: string;
    tier_name: string | null;
    rebate_percent: string | null;
    price: MoneyValue;
}

/** Mirrors App\Queries\ItemPricingQuery::customerOverrides() — Product Detail's Pricing tab. */
export interface ItemCustomerPriceRow {
    customer_id: string;
    company_name: string | null;
    price: MoneyValue;
}

export interface ItemPricing {
    tiers: ItemTierPriceRow[];
    customers: ItemCustomerPriceRow[];
}

/** Mirrors App\Queries\ItemStockRankingQuery::get() — the drawer's Provenance line. Null when there's nothing to rank against. */
export interface ItemStockRank {
    rank: number;
    total: number;
}

/** Mirrors App\Queries\ItemCustomerAttributionQuery::get() — the drawer's "Who's buying it". */
export interface ItemCustomerAttributionRow {
    customer_id: string;
    company_name: string;
    units: number;
    /** 0–1 share of this item's total ordered volume, across the rows returned. */
    share: number;
    last_ordered: string | null;
}

/** Mirrors App\DataTransferObjects\RecipeLineData::toArray() — Product Detail's Recipe and Produce tabs. */
export interface RecipeLine {
    /** Null for a custom (non-catalog) line — not reachable from any UI yet; the write path only ever creates catalog lines. */
    input_id: string | null;
    input_name: string;
    input_sku: string;
    input_unit: string;
    quantity: string;
    input_group: string | null;
    /** Decimal string — the input's current stock, null for a custom line. */
    input_stock: string | null;
}

/** Mirrors App\Services\Inventory\RecipeInputOptions::list() — the Recipe tab's "add ingredient" picker. */
export interface RecipeInputOption {
    id: string;
    name: string;
    sku: string;
    vintage: number | null;
    unit: string;
    group: string | null;
}

/** Mirrors App\Services\Inventory\InventoryMediaPresenter::image() — Product Detail's Images tab. */
export interface InventoryImageItem {
    id: string;
    alt: string | null;
    content_type: string;
    size_bytes: number;
    sort_order: number;
    /** Short-lived presigned GET URL — re-fetched with every page load, never cached across sessions. */
    url: string;
}

/** Mirrors App\Services\Inventory\InventoryMediaPresenter::techSheet()/document() — Product Detail's Docs tab. */
export interface InventoryFileItem {
    id: string;
    name: string;
    content_type: string;
    size_bytes: number;
    url: string;
}

/** Mirrors App\DataTransferObjects\BottleAnalysisData::toArray() — Product Detail's Analysis tab. */
export interface BottleAnalysisRow {
    id: string;
    analyzed_on: string;
    note: string | null;
    ph: number | null;
    total_acidity: number | null;
    volatile_acidity: number | null;
    alcohol: number | null;
    residual_sugar: number | null;
    free_so2: number | null;
    total_so2: number | null;
    temperature: number | null;
    density: number | null;
    tpi: number | null;
}

/** Mirrors App\Support\InventoryItemFilters::fromRequest(). */
export interface InventoryFilters {
    search: string | null;
    category: string | null;
    is_active: boolean | null;
    is_for_sale: boolean | null;
    sellable: boolean;
    /** Inventory Analytics' "Add costs" deep-link — active items with no cost_per_unit. */
    missing_cost: boolean;
}
