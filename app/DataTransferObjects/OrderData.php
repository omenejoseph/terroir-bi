<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Full order representation for the API. COGS (cost_per_unit) is only included
 * when the caller may see financials.
 *
 * @implements Arrayable<string, mixed>
 */
final class OrderData implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $payment
     */
    public function __construct(
        public readonly Order $order,
        public readonly bool $showFinancials,
        public readonly ?array $payment = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payment
     */
    public static function fromModel(Order $order, bool $showFinancials, ?array $payment = null): self
    {
        return new self($order, $showFinancials, $payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $order = $this->order;
        $customer = $order->customer;

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'total_amount' => $order->total_amount->jsonSerialize(),
            'notes' => $order->notes,
            'customer' => $customer !== null
                ? ['id' => $customer->getKey(), 'company_name' => $customer->company_name]
                : null,
            'created_by' => $this->user($order->createdBy),
            'is_backorder' => $order->is_backorder,
            'backorder_date' => $order->backorder_date?->toIso8601String(),
            'shipping_cost' => $order->shipping_cost?->jsonSerialize(),
            'shipping_paid_by_us' => $order->shipping_paid_by_us,
            'is_consignment' => $order->is_consignment,
            'consignment_closed_at' => $order->consignment_closed_at?->toIso8601String(),
            'payment' => $this->payment,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn (OrderItem $item) => $this->item($item))->all(),
            'status_history' => $order->statusHistories
                ->sortBy('created_at')
                ->values()
                ->map(fn (OrderStatusHistory $h) => [
                    'status' => $h->status->value,
                    'note' => $h->note,
                    'changed_by' => $this->user($h->changedBy),
                    'created_at' => $h->created_at?->toIso8601String(),
                ])->all(),
            'comments' => $order->orderNotes
                ->sortBy('created_at')
                ->values()
                ->map(fn (OrderNote $n) => [
                    'id' => $n->getKey(),
                    'content' => $n->content,
                    'author' => $this->user($n->author),
                    'created_at' => $n->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item): array
    {
        $product = $item->inventoryItem;
        $hasProduct = $product instanceof InventoryItem;

        $row = [
            'id' => $item->getKey(),
            'inventory_item_id' => $item->inventory_item_id,
            'name' => $hasProduct ? $product->name : $item->custom_description,
            'sku' => $hasProduct ? $product->sku : null,
            'quantity' => $item->quantity,
            'unit_type' => $item->unit_type,
            'unit_price' => $item->unit_price->jsonSerialize(),
            'total' => $item->total->jsonSerialize(),
            'custom_description' => $item->custom_description,
        ];

        if ($this->showFinancials) {
            $row['cost_per_unit'] = $item->cost_per_unit?->jsonSerialize();
        }

        return $row;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return ['id' => $user->getKey(), 'name' => $name];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
