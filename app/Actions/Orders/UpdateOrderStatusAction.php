<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

class UpdateOrderStatusAction
{
    public function __construct(private readonly Notifier $notifier) {}

    public function execute(Order $order, OrderStatus $status, ?string $note, string $changedById): Order
    {
        DB::transaction(function () use ($order, $status, $note, $changedById): void {
            $order->status = $status;
            $order->save();

            $order->statusHistories()->create([
                'status' => $status,
                'note' => $note,
                'changed_by_id' => $changedById,
            ]);
        });

        $this->notifier->orderStatusChanged($order, $changedById);

        return $order;
    }
}
