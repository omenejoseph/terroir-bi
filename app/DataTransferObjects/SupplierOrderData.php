<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class SupplierOrderData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly SupplierOrder $order) {}

    public static function fromModel(SupplierOrder $order): self
    {
        return new self($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $o = $this->order;
        $supplier = $o->supplier;

        $data = [
            'id' => $o->getKey(),
            'order_number' => $o->order_number,
            'status' => $o->status->value,
            'total_amount' => $o->total_amount->jsonSerialize(),
            'notes' => $o->notes,
            'sent_at' => $o->sent_at?->toIso8601String(),
            'expected_at' => $o->expected_at?->toIso8601String(),
            'received_at' => $o->received_at?->toIso8601String(),
            'supplier' => $supplier !== null ? ['id' => $supplier->getKey(), 'company_name' => $supplier->company_name] : null,
        ];

        if ($o->relationLoaded('items')) {
            $data['items'] = $o->items->map(fn (SupplierOrderItem $i) => [
                'id' => $i->getKey(),
                'inventory_item_id' => $i->inventory_item_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit' => $i->unit,
                'unit_price' => $i->unit_price->jsonSerialize(),
                'total' => $i->total->jsonSerialize(),
            ])->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
