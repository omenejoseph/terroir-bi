import type { LinePayload, OrderLineDraft } from '@/types/orders';

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
