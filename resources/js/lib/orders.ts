import type { LinePayload, OrderLineDraft, ProductOption } from '@/types/orders';

/** App\Enums\SalesUnit's two values, as select options — a custom line may pick either; a catalog line's is fixed by the product. */
export const UNIT_OPTIONS = [
    { value: 'bottles', label: 'Bottles' },
    { value: 'cases', label: 'Cases' },
];

/**
 * Draft lines (OrderLineFields' local UI state) → the payload
 * StoreOrderRequest / AddOrderItemsRequest actually validate. Only custom
 * lines carry a price — sending one for a catalog line would override the
 * customer's negotiated price with the list price.
 */
export function linesToPayload(lines: OrderLineDraft[]): LinePayload[] {
    return lines.map((line) => ({
        inventory_item_id: line.inventory_item_id,
        quantity: line.quantity,
        unit_type: line.unit_type,
        ...(line.inventory_item_id === null
            ? { unit_price: line.unit_price ?? 0, custom_description: line.custom_description }
            : {}),
    }));
}

/**
 * Price of one unit of a draft line, in minor units. A catalog line's list price is per bottle, so a
 * case line is scaled by the product's bottles per case (as OrderLineWriter does); a custom line
 * carries its own price.
 */
export function lineUnitMinor(
    line: Pick<OrderLineDraft, 'inventory_item_id' | 'unit_type' | 'unit_price' | 'preview'>,
    products: Pick<ProductOption, 'id' | 'bottles_per_case'>[],
): number {
    if (line.inventory_item_id === null) return line.unit_price ?? 0;

    const perBottle = line.preview?.minor ?? 0;
    const product = products.find((p) => p.id === line.inventory_item_id);

    return line.unit_type === 'cases' ? perBottle * Math.max(1, product?.bottles_per_case ?? 1) : perBottle;
}
