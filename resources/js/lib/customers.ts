/**
 * Customer vocabulary shared by the list, the drawers and the detail page.
 *
 * A note on types. The design (Figma 230:2395) shows Hotel, Restaurant, Agency,
 * Wholesale and Retail in the Type column. `App\Enums\CustomerType` has
 * Wholesale, Retail, Agency, Shipshop and Other — Hotel and Restaurant are not
 * values this system stores, and inventing them client-side would let a filter
 * offer a type no customer can have. The labels below are the enum's; the
 * divergence is logged in docs/design/README.md.
 */
export const CUSTOMER_TYPES = [
    { value: 'WHOLESALE', label: 'Wholesale' },
    { value: 'RETAIL', label: 'Retail' },
    { value: 'AGENCY', label: 'Agency' },
    { value: 'SHIPSHOP', label: 'Ship shop' },
    { value: 'OTHER', label: 'Other' },
] as const;

const TYPE_LABELS = new Map<string, string>(CUSTOMER_TYPES.map((t) => [t.value, t.label]));

export function customerTypeLabel(value: string | null): string {
    if (value === null) return '—';

    return TYPE_LABELS.get(value) ?? value;
}

/** How a resolved price came about, in the pricing engine's precedence order. */
export const PRICE_SOURCE_LABELS: Record<string, string> = {
    customer: 'Customer price',
    tier: 'Tier price',
    list: 'List price',
    none: 'No price',
};

/** "Restaurant · Zadar" — the identity line used under a customer's name. */
export function customerSubtitle(type: string | null, city: string | null): string | null {
    return [type === null ? null : customerTypeLabel(type), city].filter(Boolean).join(' · ') || null;
}
