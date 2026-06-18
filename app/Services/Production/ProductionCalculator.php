<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\PlanUnit;
use App\Models\InventoryItem;
use App\Models\ProductionPlan;
use App\Models\RecipeItem;

/**
 * Expands a production plan's rows into per-product revenue/cost/margin and
 * aggregates the raw-material requirements from each product's recipe. All money
 * is integer minor units. Pure read — no writes.
 */
class ProductionCalculator
{
    /**
     * @return array{rows: list<array<string, mixed>>, materials: list<array<string, mixed>>, totals: array{revenue: int, cost: int, margin_pct: float}}
     */
    public function calculate(ProductionPlan $plan): array
    {
        $rows = [];
        $totalRevenue = 0;
        $totalCost = 0;
        /** @var array<string, array{name: string, unit: string, quantity: float, cost: int}> $materials */
        $materials = [];

        foreach ($plan->rows()->with('baseItem')->orderBy('sort_order')->get() as $row) {
            $item = $row->baseItem;
            if (! $item instanceof InventoryItem) {
                continue;
            }

            $bottles = $this->toBottles((float) $row->quantity, $row->plan_unit, (int) $item->bottles_per_case);
            $pricePerBottle = $item->default_price?->getMinorAmount() ?? 0;
            $costPerBottle = $item->cost_per_unit?->getMinorAmount() ?? 0;
            $revenue = (int) round($pricePerBottle * $bottles);
            $cost = (int) round($costPerBottle * $bottles);

            // Expand the recipe into raw-material requirements.
            foreach (RecipeItem::query()->where('output_id', $item->getKey())->with('input')->get() as $line) {
                $required = (float) $line->quantity * $bottles;
                $key = $line->input_id ?? ('custom:'.$line->custom_name);
                $name = $line->input instanceof InventoryItem ? $line->input->name : (string) $line->custom_name;
                $unit = $line->input instanceof InventoryItem ? (string) $line->input->unit : (string) $line->custom_unit;
                $unitCost = $line->input instanceof InventoryItem
                    ? ($line->input->cost_per_unit?->getMinorAmount() ?? 0)
                    : ($line->custom_cost?->getMinorAmount() ?? 0);
                $materials[$key] ??= ['name' => $name, 'unit' => $unit, 'quantity' => 0.0, 'cost' => 0];
                $materials[$key]['quantity'] += $required;
                $materials[$key]['cost'] += (int) round($unitCost * $required);
            }

            $totalRevenue += $revenue;
            $totalCost += $cost;
            $rows[] = [
                'row_id' => $row->getKey(),
                'base_item_id' => $item->getKey(),
                'name' => $item->name,
                'quantity' => (string) $row->quantity,
                'plan_unit' => $row->plan_unit->value,
                'bottles' => $bottles,
                'new_vintage' => $row->new_vintage,
                'revenue' => $revenue,
                'cost' => $cost,
                'margin_pct' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
            ];
        }

        return [
            'rows' => $rows,
            'materials' => array_values(array_map(fn (array $m): array => [
                'name' => $m['name'],
                'unit' => $m['unit'],
                'quantity' => round($m['quantity'], 3),
                'cost' => $m['cost'],
            ], $materials)),
            'totals' => [
                'revenue' => $totalRevenue,
                'cost' => $totalCost,
                'margin_pct' => $totalRevenue > 0 ? round(($totalRevenue - $totalCost) / $totalRevenue * 100, 1) : 0.0,
            ],
        ];
    }

    private function toBottles(float $quantity, PlanUnit $unit, int $bottlesPerCase): int
    {
        return match ($unit) {
            PlanUnit::Liters => (int) ceil($quantity / 0.75),
            PlanUnit::Cases => (int) round($quantity * max(1, $bottlesPerCase)),
            PlanUnit::Bottles => (int) round($quantity),
        };
    }
}
