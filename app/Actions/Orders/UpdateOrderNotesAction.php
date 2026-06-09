<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;

class UpdateOrderNotesAction
{
    public function execute(Order $order, ?string $notes): Order
    {
        $order->notes = $notes;
        $order->save();

        return $order;
    }
}
