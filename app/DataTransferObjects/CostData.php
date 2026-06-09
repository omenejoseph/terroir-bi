<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Cost;
use App\Models\CostAttachment;
use App\Models\CostItem;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class CostData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly Cost $cost) {}

    public static function fromModel(Cost $cost): self
    {
        return new self($cost);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $c = $this->cost;
        $supplier = $c->supplier;

        $data = [
            'id' => $c->getKey(),
            'date' => $c->date->toIso8601String(),
            'total_amount' => $c->total_amount->jsonSerialize(),
            'vat_amount' => $c->vat_amount?->jsonSerialize(),
            'category' => $c->category,
            'description' => $c->description,
            'reference' => $c->reference,
            'status' => $c->status->value,
            'payment_method' => $c->payment_method,
            'notes' => $c->notes,
            'paid_at' => $c->paid_at?->toIso8601String(),
            'due_date' => $c->due_date?->toIso8601String(),
            'supplier' => $supplier !== null ? ['id' => $supplier->getKey(), 'company_name' => $supplier->company_name] : null,
        ];

        if ($c->relationLoaded('items')) {
            $data['items'] = $c->items->map(fn (CostItem $i) => [
                'id' => $i->getKey(),
                'inventory_item_id' => $i->inventory_item_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price->jsonSerialize(),
                'total' => $i->total->jsonSerialize(),
                'category' => $i->category,
            ])->all();
        }

        if ($c->relationLoaded('attachments')) {
            $data['attachments'] = $c->attachments->map(fn (CostAttachment $a) => [
                'id' => $a->getKey(),
                'filename' => $a->filename,
                'content_type' => $a->content_type,
                'size_bytes' => $a->size_bytes,
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
