import type { MoneyValue } from '@/types/inventory';

/** GET /api/v1/public/{token}/catalog — Api\PublicOrderController::catalog(). */
export interface PublicCatalogProduct {
    id: string;
    name: string;
    sku: string | null;
    vintage: number | null;
    /** The item's own unit label ("bottles", "cases", …) — display only. */
    unit: string;
    unit_size: string | null;
    group: string | null;
    subcategory: string | null;
    /** Lead product image (signed URL), when the item has one. */
    image_url: string | null;
    /** What this item must be ordered in — "bottles" or "cases", strict. */
    sales_unit: 'bottles' | 'cases';
    bottles_per_case: number | null;
    /** Absent entirely when the customer has prices hidden. */
    price?: MoneyValue;
}

export interface PublicCatalogCustomer {
    company_name: string;
    hide_prices: boolean;
    /** false means this customer orders by the case only. */
    allow_single_bottle: boolean;
}

export interface PublicCatalog {
    /** The winery's own name, for the page header. */
    business_name: string | null;
    customer: PublicCatalogCustomer;
    products: PublicCatalogProduct[];
}
