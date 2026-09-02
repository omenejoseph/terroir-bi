/**
 * Display labels for stock enums.
 *
 * The wire carries raw enum values (`ORDER_DEDUCT`, `sales`); the design shows
 * prose ("Order", "Sales"). Mapping here keeps every screen naming a movement
 * the same way, and keeps the raw value out of the UI.
 */

/** App\Enums\StockMovementType → the design's wording. */
const MOVEMENT_TYPES: Record<string, string> = {
    MANUAL_IN: 'Stock In',
    MANUAL_OUT: 'Stock Out',
    ORDER_DEDUCT: 'Order',
    PRODUCTION_IN: 'Produced',
    PRODUCTION_OUT: 'Consumed',
    PURCHASE_IN: 'Received',
    ADJUSTMENT: 'Adjustment',
};

export function movementTypeLabel(type: string): string {
    return MOVEMENT_TYPES[type] ?? type;
}

/**
 * The movement-history filter groups (Figma 449:1577: All / Orders / Produced /
 * Adjustments), expressed as the movement types each covers.
 */
export const MOVEMENT_GROUPS: Record<string, string[] | null> = {
    All: null,
    Orders: ['ORDER_DEDUCT'],
    Produced: ['PRODUCTION_IN', 'PRODUCTION_OUT'],
    Adjustments: ['ADJUSTMENT', 'MANUAL_IN', 'MANUAL_OUT', 'PURCHASE_IN'],
};

/**
 * InventoryItemStockAnalyticsQuery's channel keys.
 *
 * NOTE: these are *movement-type* channels. The design's "Exit by channel" card
 * shows *customer sales* channels (Internal / POS, Distributor / Importer,
 * Retailer / Shop), which needs channel attribution on order lines that the
 * schema does not have. See docs/design/README.md.
 */
const CHANNELS: Record<string, string> = {
    sales: 'Sales',
    production: 'Production',
    manual: 'Manual',
};

export function channelLabel(channel: string): string {
    return CHANNELS[channel] ?? channel;
}

/** "2026-09-02T14:36:00Z" → "2 Sep 14:36", matching the design's compact form. */
export function formatMovementDate(iso: string | null, locale: string): string {
    if (!iso) return '—';

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) return iso;

    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

/** App\Enums\InventoryCategory → the design's wording. */
const CATEGORIES: Record<string, string> = {
    FINISHED: 'Finished',
    SEMI_FINISHED: 'Semi-finished',
    RAW_MATERIAL: 'Raw materials',
};

export function categoryLabel(category: string): string {
    return CATEGORIES[category] ?? category;
}

/** "2026-08" → "Aug 26", the compact axis label the design uses. */
export function formatMonth(month: string, locale: string): string {
    const [year, m] = month.split('-');
    const date = new Date(Number(year), Number(m) - 1, 1);

    if (Number.isNaN(date.getTime())) return month;

    return new Intl.DateTimeFormat(locale, { month: 'short', year: '2-digit' }).format(date);
}
