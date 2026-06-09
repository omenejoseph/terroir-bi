<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Supplier;
use App\Models\SupplierPriceItem;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class SupplierData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly Supplier $supplier) {}

    public static function fromModel(Supplier $supplier): self
    {
        return new self($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $s = $this->supplier;

        $data = [
            'id' => $s->getKey(),
            'company_name' => $s->company_name,
            'contact_name' => $s->contact_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'address' => $s->address,
            'city' => $s->city,
            'country' => $s->country,
            'tax_id' => $s->tax_id,
            'bank_account' => $s->bank_account,
            'payment_terms' => $s->payment_terms,
            'notes' => $s->notes,
            'is_active' => $s->is_active,
            'exclude_from_stats' => $s->exclude_from_stats,
            'price_items_count' => $s->getAttribute('price_items_count'),
        ];

        if ($s->relationLoaded('priceItems')) {
            $data['price_items'] = $s->priceItems->map(fn (SupplierPriceItem $p) => [
                'id' => $p->getKey(),
                'inventory_item_id' => $p->inventory_item_id,
                'description' => $p->description,
                'unit_price' => $p->unit_price->jsonSerialize(),
                'unit' => $p->unit,
                'notes' => $p->notes,
                'last_updated' => $p->last_updated?->toIso8601String(),
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
