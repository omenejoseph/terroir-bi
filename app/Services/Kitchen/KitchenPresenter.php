<?php

declare(strict_types=1);

namespace App\Services\Kitchen;

use App\Enums\KitchenCategory;
use App\Enums\KitchenLocation;
use App\Enums\KitchenUnit;
use App\Enums\KitchenWorkOrderStatus;
use App\Models\KitchenCheck;
use App\Models\KitchenCheckItem;
use App\Models\KitchenItem;
use App\Models\KitchenMovement;
use App\Models\KitchenWorkOrder;
use App\Models\KitchenWorkOrderItem;

/** Shapes the kitchen's items, movements, stock-takes and prep tickets for the Kitchen page. */
class KitchenPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function item(KitchenItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'name' => $item->name,
            'category' => $item->category->value,
            'group' => $item->group,
            'unit' => $item->unit->value,
            'current_stock' => (string) $item->current_stock,
            'min_stock' => $item->min_stock === null ? null : (string) $item->min_stock,
            'cost_per_unit' => $item->cost_per_unit?->jsonSerialize(),
            'supplier' => $item->supplier,
            'location' => $item->location->value,
            'is_active' => $item->is_active,
            'is_low' => $item->isLow(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function movement(KitchenMovement $movement): array
    {
        return [
            'id' => $movement->getKey(),
            'type' => $movement->type->value,
            'quantity' => (string) $movement->quantity,
            'note' => $movement->note,
            'by' => $movement->user?->fullName(),
            'at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkSummary(KitchenCheck $check): array
    {
        return [
            'id' => $check->getKey(),
            'date' => $check->date->toDateString(),
            'status' => $check->status->value,
            'location' => $check->location?->value,
            'note' => $check->note,
            'completed_at' => $check->completed_at?->toIso8601String(),
            'by' => $check->user?->fullName(),
            'lines' => (int) ($check->getAttribute('items_count') ?? 0),
            'counted' => (int) ($check->getAttribute('counted_count') ?? 0),
        ];
    }

    /**
     * A stock-take with its lines, grouped the way the count sheet is walked: by category, then name.
     *
     * @return array<string, mixed>
     */
    public function checkDetail(KitchenCheck $check): array
    {
        $lines = $check->items()->with('item')->get()
            ->sortBy(fn (KitchenCheckItem $line): string => ($line->item?->category->value ?? '').'|'.mb_strtolower((string) $line->item?->name))
            ->values();

        return [
            ...$this->checkSummary($check->loadCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('physical_count')])),
            'items' => $lines->map(fn (KitchenCheckItem $line): array => [
                'id' => $line->getKey(),
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'category' => $line->item?->category->value,
                'unit' => $line->item?->unit->value,
                'system_stock' => (string) $line->system_stock,
                'physical_count' => $line->physical_count === null ? null : (string) $line->physical_count,
                'difference' => $line->difference === null ? null : (string) $line->difference,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workOrder(KitchenWorkOrder $order): array
    {
        return [
            'id' => $order->getKey(),
            'title' => $order->title,
            'date' => $order->date->toDateString(),
            'status' => $order->status->value,
            'assigned_to' => $order->assigned_to,
            'notes' => $order->notes,
            'completed_at' => $order->completed_at?->toIso8601String(),
            'by' => $order->user?->fullName(),
            'items' => $order->items->map(fn (KitchenWorkOrderItem $line): array => [
                'id' => $line->getKey(),
                'item_id' => $line->item_id,
                'name' => $line->item?->name ?? $line->custom_name,
                'custom_name' => $line->custom_name,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit ?? $line->item?->unit->value,
                'notes' => $line->notes,
            ])->values()->all(),
        ];
    }

    /**
     * The labelled choices the forms and filters offer.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $list = fn (array $cases): array => array_map(fn ($c): array => ['value' => $c->value, 'label' => __($c->label())], $cases);

        return [
            'categories' => $list(KitchenCategory::cases()),
            'locations' => $list(KitchenLocation::cases()),
            'units' => $list(KitchenUnit::cases()),
            'work_order_statuses' => $list(KitchenWorkOrderStatus::cases()),
        ];
    }
}
