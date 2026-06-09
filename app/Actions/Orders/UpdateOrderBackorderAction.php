<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Set or clear the expected fulfilment date (ADMIN-gated route).
 */
class UpdateOrderBackorderAction
{
    public function execute(Order $order, ?string $backorderDate): Order
    {
        $order->backorder_date = $backorderDate !== null ? Carbon::parse($backorderDate) : null;
        $order->save();

        return $order;
    }
}
