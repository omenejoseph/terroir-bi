<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenWorkOrderStatus;
use App\Models\KitchenWorkOrder;

class ChangeKitchenWorkOrderStatusAction
{
    /** Completing stamps the time; moving it back off Completed clears it. */
    public function execute(KitchenWorkOrder $order, KitchenWorkOrderStatus $status): KitchenWorkOrder
    {
        $order->status = $status;
        $order->completed_at = $status === KitchenWorkOrderStatus::Completed ? ($order->completed_at ?? now()) : null;
        $order->save();

        return $order;
    }
}
