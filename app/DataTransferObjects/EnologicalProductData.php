<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\EnologicalProduct;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class EnologicalProductData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly EnologicalProduct $product) {}

    public static function fromModel(EnologicalProduct $product): self
    {
        return new self($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $p = $this->product;

        return [
            'id' => $p->getKey(),
            'name' => $p->name,
            'category' => $p->category,
            'unit' => $p->unit,
            'current_stock' => (string) $p->current_stock,
            'min_stock' => $p->min_stock !== null ? (string) $p->min_stock : null,
            'cost_per_unit' => $p->cost_per_unit?->jsonSerialize(),
            'manufacturer' => $p->manufacturer,
            'packaging_size' => $p->packaging_size,
            'so2_uplift_per_unit' => $p->so2_uplift_per_unit !== null ? (string) $p->so2_uplift_per_unit : null,
            'supplier_id' => $p->supplier_id,
            'is_active' => $p->is_active,
            'notes' => $p->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
