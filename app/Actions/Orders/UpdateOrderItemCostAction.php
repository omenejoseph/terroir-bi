<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderItem;
use App\Services\Orders\OrderTotals;
use App\Support\Money\Money;

/**
 * Override a line's COGS snapshot. Exempt from the edit window (true costs and
 * invoices arrive after the order).
 */
class UpdateOrderItemCostAction
{
    public function __construct(private readonly OrderTotals $totals) {}

    public function execute(OrderItem $item, ?int $costMinor): OrderItem
    {
        $order = $item->order()->firstOrFail();
        $currency = $this->totals->currency($order);

        $item->cost_per_unit = $costMinor !== null ? Money::fromMinor($costMinor, $currency) : null;
        $item->save();

        return $item;
    }
}
