<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Services\Orders\OrderTotals;
use App\Support\Money\Money;

/**
 * Set the order's freight cost. Exempt from the edit window (the carrier invoice
 * lands later). When a cost is present, "paid by us" defaults true unless the
 * caller says otherwise.
 */
class UpdateOrderShippingAction
{
    public function __construct(private readonly OrderTotals $totals) {}

    public function execute(Order $order, ?int $shippingMinor, ?bool $paidByUs): Order
    {
        $currency = $this->totals->currency($order);

        $order->shipping_cost = $shippingMinor !== null ? Money::fromMinor($shippingMinor, $currency) : null;
        $order->shipping_paid_by_us = $paidByUs ?? ($shippingMinor !== null);
        $order->save();

        return $order;
    }
}
