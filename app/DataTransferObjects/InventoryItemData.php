<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryItem;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class InventoryItemData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $sku,
        public readonly string $category,
        public readonly string $unit,
        public readonly string $currentStock,
        public readonly bool $isActive,
        public readonly bool $isForSale,
        public readonly ?InventoryItem $model = null,
    ) {}

    public static function fromModel(InventoryItem $item): self
    {
        return new self(
            id: $item->getKey(),
            name: $item->name,
            sku: $item->sku,
            category: $item->category->value,
            unit: $item->unit,
            currentStock: (string) $item->current_stock,
            isActive: $item->is_active,
            isForSale: $item->is_for_sale,
            model: $item,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $item = $this->model;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'category' => $this->category,
            'group' => $item?->group,
            'subcategory' => $item?->subcategory,
            'vintage' => $item?->vintage,
            'unit' => $this->unit,
            'current_stock' => $this->currentStock,
            'min_stock' => $item !== null ? $item->min_stock : null,
            'is_active' => $this->isActive,
            'is_for_sale' => $this->isForSale,
            'sort_order' => $item?->sort_order,
            'bottles_per_case' => $item?->bottles_per_case,
            'default_price' => $item?->default_price?->jsonSerialize(),
            'cost_per_unit' => $item?->cost_per_unit?->jsonSerialize(),
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
