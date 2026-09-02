<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Support\Money\Money;

/**
 * The two option lists the Create Order drawer (Figma 335:4233) picks from:
 * who the order is for, and what can go on it.
 *
 * Deliberately narrow. These feed comboboxes, not tables, so they carry the
 * identifying fields and the indicative price and nothing else — no order
 * counts, no revenue sums, no presigned images.
 *
 * The price here is the catalog list price, NOT what the line will cost. The
 * customer's tier and rebate are applied by OrderLineWriter when the order is
 * written, so the drawer shows this as an estimate and the server decides.
 */
class OrderFormOptions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function customers(): array
    {
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'customer_type', 'city', 'rebate_percent'])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->getKey(),
                'company_name' => $customer->company_name,
                'customer_type' => $customer->customer_type,
                'city' => $customer->city,
                'rebate_percent' => $customer->rebate_percent,
            ])
            ->all();
    }

    /**
     * Sellable catalog items only: an order line cannot reference something
     * that is inactive or not for sale, so offering it would only produce a
     * validation failure at the end of the form.
     *
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->where('is_for_sale', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->getKey(),
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'unit_size' => $item->unit_size,
                'sales_unit' => $item->sales_unit,
                'bottles_per_case' => $item->bottles_per_case,
                'list_price' => $item->default_price instanceof Money
                    ? $item->default_price->jsonSerialize()
                    : null,
            ])
            ->all();
    }
}
