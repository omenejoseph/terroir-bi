<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class UpdateOrderStatusAction
{
    public function execute(Order $order, OrderStatus $status, ?string $note, string $changedById): Order
    {
        return DB::transaction(function () use ($order, $status, $note, $changedById): Order {
            $order->status = $status;
            $order->save();

            $order->statusHistories()->create([
                'status' => $status,
                'note' => $note,
                'changed_by_id' => $changedById,
            ]);

            return $order;
        });
    }
}
