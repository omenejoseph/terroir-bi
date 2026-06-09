<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderLineWriter;
use App\Services\Orders\OrderNumberGenerator;
use App\Services\Orders\OrderTotals;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Create an internal order (flow 01): generate a number, snapshot COGS per line,
 * deduct stock (overdraw-guarded) unless it's a backorder, record the opening
 * status, and total it up — all in one transaction.
 */
class CreateOrderAction
{
    public function __construct(
        private readonly OrderNumberGenerator $numbers,
        private readonly OrderLineWriter $lines,
        private readonly OrderTotals $totals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Customer $customer, string $createdById, array $data): Order
    {
        return DB::transaction(function () use ($customer, $createdById, $data): Order {
            $status = isset($data['status'])
                ? OrderStatus::from((string) $data['status'])
                : OrderStatus::Received;

            $isBackorder = (bool) ($data['is_backorder'] ?? false);
            $shippingCost = isset($data['shipping_cost']) ? (int) $data['shipping_cost'] : null;

            $order = Order::create([
                'order_number' => $this->numbers->next(),
                'status' => $status,
                'customer_id' => $customer->getKey(),
                'created_by_id' => $createdById,
                'notes' => $data['notes'] ?? null,
                'is_backorder' => $isBackorder,
                'backorder_date' => $isBackorder ? ($data['backorder_date'] ?? null) : null,
                'is_consignment' => (bool) ($data['is_consignment'] ?? false),
                'shipping_paid_by_us' => (bool) ($data['shipping_paid_by_us'] ?? ($shippingCost !== null)),
            ]);

            $currency = $this->totals->currency($order);

            if ($shippingCost !== null) {
                $order->shipping_cost = Money::fromMinor($shippingCost, $currency);
                $order->save();
            }

            $order->statusHistories()->create([
                'status' => $status,
                'note' => 'Order created',
                'changed_by_id' => $createdById,
            ]);

            /** @var list<array<string, mixed>> $items */
            $items = $data['items'] ?? [];
            foreach ($items as $line) {
                $this->lines->write($order, $customer, $currency, $line);
            }

            $this->totals->recompute($order);

            return $order;
        });
    }
}
