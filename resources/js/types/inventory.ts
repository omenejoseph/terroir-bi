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
    /** The category record this item is filed under (its leaf: the subcategory when it has one). */
    product_category_id: string | null;
    vintage: number | null;
    /** The vintage this one follows — the chain the list folds under the latest vintage. */
    previous_vintage_id: string | null;
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
    /** Only on the Inventory list: the older vintages under this product's latest one, newest first. */
    previous_vintages?: InventoryItem[];
}

/** Mirrors App\Services\Inventory\ProductCategoryOptions::tree(). */
export interface ProductCategory {
    id: string;
    name: string;
    /** The InventoryCategory enum value the category lives under. */
    type: string;
    has_vintage: boolean;
    sort_order: number;
    /** A top-level category counts its subcategories' items too. */
    items_count: number;
    children?: ProductCategory[];
}

/** Mirrors App\Services\Inventory\ProductCategoryOptions::vintageCandidates(). */
export interface VintageOption {
    id: string;
    name: string;
    vintage: string | null;
    unit_size: string | null;
    type: string;
    product_category_id: string;
    /** Already the previous vintage of another product, so it can't be picked again. */
    has_next: boolean;
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
    /** Null for a cellar-wine or custom line. */
    input_id: string | null;
    input_name: string;
    input_sku: string;
    input_unit: string;
    quantity: string;
    input_group: string | null;
    /** Decimal string — the input's current stock, null for a custom line. */
    input_stock: string | null;
    /** catalog = an inventory item, cellar = wine from a lot, custom = a free-text ingredient. */
    kind: 'catalog' | 'cellar' | 'custom';
    wine_lot_id: string | null;
    /** The unit `quantity` is entered in (ml, cl, l, g, kg); null = the input's own unit. */
    unit: string | null;
    /** Units the quantity can be entered in — empty when the input's unit does not convert. */
    unit_options: string[];
    /** What one of the line's own unit costs (a fraction of a minor unit is normal for ml). Null when unpriced. */
    unit_cost: { minor: number; currency: string } | null;
    line_cost: MoneyValue | null;
    /** Custom lines only: cost per custom unit, minor units. */
    custom_cost: number | null;
}

/** Mirrors App\Services\Inventory\RecipeCostSummary::for(). */
export interface RecipeCostSummary {
    per_unit: MoneyValue | null;
    /** False when some line has no cost, so per_unit understates the real figure. */
    complete: boolean;
    /** Bottles per output unit (12 for an item stocked in cases of 12), 1 otherwise. */
    multiplier: number;
}

/** Mirrors App\Services\Inventory\RecipeInputOptions::cellarLots(). */
export interface RecipeCellarLot {
    id: string;
    name: string;
    lot_number: string;
    vintage: number | null;
    current_volume: string;
    cost_per_liter: { minor: number; currency: string };
}

/** Mirrors App\Services\Inventory\RecipeInputOptions::sources() — a product with a recipe, to copy from. */
export interface RecipeSource {
    id: string;
    name: string;
    vintage: number | null;
    lines: {
        input_id: string | null;
        wine_lot_id: string | null;
        quantity: string;
        unit: string | null;
        custom_name: string | null;
        custom_unit: string | null;
        custom_cost: number | null;
    }[];
}

/** Mirrors App\Services\Inventory\RecipeInputOptions::list() — the Recipe tab's "add ingredient" picker. */
export interface RecipeInputOption {
    id: string;
    name: string;
    sku: string;
    vintage: number | null;
    unit: string;
    group: string | null;
    /** What one unit costs: the cost per pack divided by the pack size. Fractional minor units are normal. */
    unit_cost: { minor: number; currency: string } | null;
    pack_size: number;
    unit_options: string[];
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
    /** A category (or, for a top-level one, any of its subcategories). */
    product_category_id: string | null;
    is_active: boolean | null;
    is_for_sale: boolean | null;
    sellable: boolean;
    /** Inventory Analytics' "Add costs" deep-link — active items with no cost_per_unit. */
    missing_cost: boolean;
    /** The active "Needs attention" chip, applied on the server. */
    attention: string | null;
}

/** Mirrors App\Queries\ItemAvailabilityQuery::get() — the drawer's stock waterfall. Quantities are decimal strings in the item's unit. */
export interface ItemAvailability {
    /** The item these figures are for — the drawer ignores a stale response for the previous item. */
    item_id: string;
    unit: string;
    on_hand: string;
    /** Set aside for customers by allocation orders that have not shipped: still on hand, but not free to sell. */
    allocated: string;
    /** On hand minus allocated; negative when more has been promised than the cellar holds. */
    free_to_sell: string;
    allocations: { order_id: string; order_number: string; customer: string | null; quantity: string }[];
    /** Already taken off the shelf by orders that have not shipped yet — context, not subtracted again. */
    in_open_orders: string;
    /** Already out with customers on open consignments — context, not subtracted again. */
    on_consignment: string;
}

/** Mirrors the `productionRuns` prop — one run of the Produce tab. */
export interface ProductionRun {
    id: string;
    quantity: string;
    reference: string;
    /** What the run took from stock; null for runs recorded before inputs were kept. */
    inputs: { name: string; sku: string; unit: string; quantity: string }[] | null;
    by: string | null;
    at: string;
}
