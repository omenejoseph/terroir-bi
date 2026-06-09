<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the COGS to freeze onto an order line at order time. Prefers a recipe
 * roll-up (sum of input costs), falling back to the item's own cost_per_unit.
 * The figure is per sales unit — a catalog line is always in the item's sales
 * unit, so it applies directly (no case scaling). Returns null when no cost is
 * known (line flagged later as "unknown cost" in analytics).
 */
class CogsSnapshot
{
    public function forLine(InventoryItem $item): ?Money
    {
        return $this->perSalesUnit($item);
    }

    private function perSalesUnit(InventoryItem $item): ?Money
    {
        /** @var Collection<int, RecipeItem> $recipe */
        $recipe = $item->recipe()->with('input')->get();

        if ($recipe->isNotEmpty()) {
            $rollup = $this->recipeRollup($recipe);

            if ($rollup !== null) {
                return $rollup;
            }
        }

        return $item->cost_per_unit;
    }

    /**
     * @param  Collection<int, RecipeItem>  $recipe
     */
    private function recipeRollup($recipe): ?Money
    {
        $totalMinor = 0;
        $currency = null;
        $priced = false;

        foreach ($recipe as $line) {
            $input = $line->input;
            $lineCost = $input instanceof InventoryItem ? $input->cost_per_unit : $line->custom_cost;

            if ($lineCost === null) {
                continue;
            }

            $currency ??= $lineCost->getCurrencyCode();
            $totalMinor += (int) round($lineCost->getMinorAmount() * (float) $line->quantity);
            $priced = true;
        }

        if (! $priced || $currency === null) {
            return null;
        }

        return Money::fromMinor($totalMinor, $currency);
    }
}
