<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\WineLotStatus;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Models\WineLot;
use App\Services\Cellar\LotCostService;
use App\Support\Measure;
use App\Support\Money\CurrencyRegistry;

/**
 * The Recipe tab's "add ingredient" pickers (Product Detail, Figma 449:1577): any other active catalog
 * item — a bill of materials draws on raw and semi-finished stock, not just sellable finished goods, so
 * this is wider than App\Services\Orders\OrderFormOptions::products() — the cellar's ready / ageing
 * wine, and the products that already have a recipe (to copy one from).
 */
class RecipeInputOptions
{
    public function __construct(private readonly LotCostService $lotCosts) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(InventoryItem $excluding): array
    {
        return array_values(InventoryItem::query()
            ->where('is_active', true)
            ->whereKeyNot($excluding->getKey())
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'vintage', 'unit', 'group', 'cost_per_unit', 'pack_size'])
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->getKey(),
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'unit' => $item->unit,
                'group' => $item->group,
                // What one unit costs (the cost per pack ÷ pack size) — lets the editor price a line as it is typed.
                'unit_cost' => $item->cost_per_unit === null ? null : [
                    'minor' => $item->cost_per_unit->getMinorAmount() / max(1, (int) $item->pack_size),
                    'currency' => $item->cost_per_unit->getCurrencyCode(),
                ],
                'pack_size' => (int) $item->pack_size,
                'unit_options' => Measure::options($item->unit),
            ])
            ->all());
    }

    /**
     * Wine in the cellar that can go into a product (a gift set, a blend of the house wine…): ready or
     * ageing, with something left in the tank. Costed per litre exactly as the Cellar shows it.
     *
     * @return list<array<string, mixed>>
     */
    public function cellarLots(): array
    {
        $currency = null;

        return array_values(WineLot::query()
            ->whereIn('status', [WineLotStatus::Ready->value, WineLotStatus::Aging->value])
            ->where('current_volume', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(function (WineLot $lot) use (&$currency): array {
                $currency ??= $lot->tenant?->settings->default_currency ?? CurrencyRegistry::default()->code;

                return [
                    'id' => $lot->getKey(),
                    'name' => $lot->name,
                    'lot_number' => $lot->lot_number,
                    'vintage' => $lot->vintage,
                    'current_volume' => (string) $lot->current_volume,
                    'cost_per_liter' => ['minor' => $this->lotCosts->breakdown($lot)['per_liter'], 'currency' => $currency],
                ];
            })
            ->all());
    }

    /**
     * Other products with a recipe, as lines ready to copy.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(InventoryItem $excluding): array
    {
        return array_values(InventoryItem::query()
            ->whereKeyNot($excluding->getKey())
            ->where('is_active', true)
            // Only products that have a recipe, and one with something that can still be copied: an
            // active catalog item, a cellar lot, or a custom line.
            ->whereHas('recipe', fn ($line) => $line
                ->whereHas('input', fn ($input) => $input->where('is_active', true))
                ->orWhereNotNull('wine_lot_id')
                ->orWhereNotNull('custom_name'))
            ->with('recipe')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->getKey(),
                'name' => $item->name,
                'vintage' => $item->vintage,
                'lines' => $item->recipe->map(fn (RecipeItem $line): array => [
                    'input_id' => $line->input_id,
                    'wine_lot_id' => $line->wine_lot_id,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->unit,
                    'custom_name' => $line->custom_name,
                    'custom_unit' => $line->custom_unit,
                    'custom_cost' => $line->custom_cost?->getMinorAmount(),
                ])->values()->all(),
            ])
            ->all());
    }
}
