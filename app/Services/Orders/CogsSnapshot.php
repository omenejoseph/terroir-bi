<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\SalesUnit;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the COGS to freeze onto an order line at order time. Prefers a recipe
 * roll-up (sum of input costs), falling back to the item's own cost_per_unit.
 * The base figure is per bottle; a case line is scaled by bottles_per_case.
 * Returns null when no cost is known (line flagged later as "unknown cost").
 */
class CogsSnapshot
{
    /**
     * Recipe roll-up per item id, for the life of this instance — an order
     * with the same item on more than one line (e.g. two case sizes of the
     * same wine) reuses the first line's lookup instead of re-querying. One
     * CogsSnapshot instance is shared across every `write()` call in a
     * single CreateOrderAction/AddOrderItemsAction run (constructor
     * injection resolves it once), and a fresh container resolution starts
     * a fresh instance for the next request, so this never leaks across
     * requests.
     *
     * @var array<string, Money|null>
     */
    private array $perBottleCache = [];

    public function forLine(InventoryItem $item, string $unitType): ?Money
    {
        $perBottle = $this->perBottle($item);

        if ($perBottle === null) {
            return null;
        }

        if ($unitType === SalesUnit::Cases->value) {
            $factor = max(1, (int) $item->bottles_per_case);

            return Money::fromMinor($perBottle->getMinorAmount() * $factor, $perBottle->getCurrencyCode());
        }

        return $perBottle;
    }

    /** Per-bottle COGS: recipe roll-up if present, else the item's own cost_per_unit. */
    public function perBottle(InventoryItem $item): ?Money
    {
        $key = (string) $item->getKey();
        if (array_key_exists($key, $this->perBottleCache)) {
            return $this->perBottleCache[$key];
        }

        /** @var Collection<int, RecipeItem> $recipe */
        $recipe = $item->recipe()->with('input')->get();
        $result = null;

        if ($recipe->isNotEmpty()) {
            $result = $this->recipeRollup($recipe);
        }

        $result ??= $item->cost_per_unit;

        return $this->perBottleCache[$key] = $result;
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
