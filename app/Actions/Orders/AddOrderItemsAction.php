<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Services\Orders\OrderEditGuard;
use App\Services\Orders\OrderLineWriter;
use App\Services\Orders\OrderTotals;
use Illuminate\Support\Facades\DB;

class AddOrderItemsAction
{
    public function __construct(
        private readonly OrderEditGuard $guard,
        private readonly OrderLineWriter $lines,
        private readonly OrderTotals $totals,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function execute(Order $order, array $items): Order
    {
        $this->guard->ensureEditable($order);

        return DB::transaction(function () use ($order, $items): Order {
            $customer = $order->customer()->firstOrFail();
            $currency = $this->totals->currency($order);

            foreach ($items as $line) {
                $this->lines->write($order, $customer, $currency, $line);
            }

            $this->totals->recompute($order);

            return $order;
        });
    }
}
