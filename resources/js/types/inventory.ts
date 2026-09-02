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

/** Mirrors App\Support\InventoryItemFilters::fromRequest(). */
export interface InventoryFilters {
    search: string | null;
    category: string | null;
    is_active: boolean | null;
    is_for_sale: boolean | null;
    sellable: boolean;
}
