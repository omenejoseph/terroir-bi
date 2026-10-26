<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Models\WineLot;
use App\Services\Cellar\LotCostService;
use App\Services\Inventory\RecipeCost;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

class SetRecipeAction
{
    public function __construct(
        private readonly RecipeCost $cost,
        private readonly LotCostService $lots,
    ) {}

    /**
     * Replace an item's recipe with the given lines — catalog items, cellar wine (`wine_lot_id`) and
     * custom ingredients (`custom_name` + `custom_cost`, minor units). When every line can be costed the
     * item's own cost_per_unit follows the recipe, so the cost shown elsewhere agrees with it.
     *
     * @param  list<array{input_id?: ?string, wine_lot_id?: ?string, quantity: string, unit?: ?string, custom_name?: ?string, custom_unit?: ?string, custom_cost?: ?int}>  $lines
     * @return list<array{input_id: string, quantity: string}>
     */
    public function execute(InventoryItem $output, array $lines): array
    {
        return DB::transaction(function () use ($output, $lines): array {
            $output->recipe()->delete();

            foreach ($lines as $position => $line) {
                $output->recipe()->create($this->attributes($line) + ['sort_order' => $position]);
            }

            $recipe = $output->recipe()->with(['input', 'wineLot'])->get();
            $this->writeCostBack($output, $recipe);

            return array_values($recipe
                ->map(fn (RecipeItem $r) => ['input_id' => (string) $r->input_id, 'quantity' => (string) $r->quantity])
                ->all());
        });
    }

    /**
     * @param  array{input_id?: ?string, wine_lot_id?: ?string, quantity: string, unit?: ?string, custom_name?: ?string, custom_unit?: ?string, custom_cost?: ?int}  $line
     * @return array<string, mixed>
     */
    private function attributes(array $line): array
    {
        $base = ['quantity' => $line['quantity'], 'unit' => $line['unit'] ?? null];

        if (filled($line['input_id'] ?? null)) {
            return $base + ['input_id' => $line['input_id']];
        }

        if (filled($line['wine_lot_id'] ?? null)) {
            $lot = WineLot::query()->findOrFail($line['wine_lot_id']);

            // The name and last per-litre cost are kept with the line, so it still reads and costs
            // sensibly if the lot is ever deleted.
            return $base + [
                'wine_lot_id' => $lot->getKey(),
                'custom_name' => $lot->name,
                'custom_unit' => 'l',
                'custom_cost' => $this->lots->breakdown($lot)['per_liter'],
                'unit' => $line['unit'] ?? 'l',
            ];
        }

        return $base + [
            'custom_name' => $line['custom_name'] ?? null,
            'custom_unit' => $line['custom_unit'] ?? null,
            'custom_cost' => $line['custom_cost'] ?? null,
        ];
    }

    /**
     * @param  iterable<RecipeItem>  $recipe
     */
    private function writeCostBack(InventoryItem $output, iterable $recipe): void
    {
        ['total' => $total, 'complete' => $complete] = $this->cost->total($recipe);

        if (! $complete || $total === null || $total->getMinorAmount() <= 0) {
            return;
        }

        $output->cost_per_unit = $this->perOutputUnit($output, $total);
        $output->save();
    }

    /** The recipe is per bottle; an item stocked in cases costs a case's worth of bottles. */
    private function perOutputUnit(InventoryItem $output, Money $perBottle): Money
    {
        $factor = in_array(strtolower($output->unit), ['case', 'cases'], true) ? max(1, (int) $output->bottles_per_case) : 1;

        return Money::fromMinor($perBottle->getMinorAmount() * $factor, $perBottle->getCurrencyCode());
    }
}
