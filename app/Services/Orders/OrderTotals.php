<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;

/**
 * Recomputes an order's total_amount from its current line items. Called after
 * any line add/edit/delete so the header total never drifts from the lines.
 */
class OrderTotals
{
    public function recompute(Order $order): void
    {
        $sum = 0;
        foreach ($order->items()->get() as $item) {
            $sum += $item->total->getMinorAmount();
        }

        $order->total_amount = Money::fromMinor($sum, $this->currency($order));
        $order->save();
    }

    public function currency(Order $order): string
    {
        $currency = $order->tenant?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
