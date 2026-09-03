import type { MoneyValue } from '@/types/inventory';

/** GET /api/v1/public/{token}/catalog — Api\PublicOrderController::catalog(). */
export interface PublicCatalogProduct {
    id: string;
    name: string;
    sku: string | null;
    vintage: number | null;
    /** The item's own unit label ("bottles", "cases", …) — display only. */
    unit: string;
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
    customer: PublicCatalogCustomer;
    products: PublicCatalogProduct[];
}
