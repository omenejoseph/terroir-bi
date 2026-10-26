<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Models\WineLot;
use App\Services\Cellar\LotCostService;
use App\Support\Measure;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;

/**
 * What a recipe costs, line by line and in total — the one definition behind the Recipe tab, the cost
 * written back to the item and the COGS frozen onto orders.
 *
 *  - catalog line: the input's cost_per_unit (per the input's own unit, divided by its pack size) × quantity;
 *  - cellar line: the lot's cost per litre (grape + additions + linked costs, as the Cellar shows it) × quantity;
 *  - custom line: its own cost × quantity.
 *
 * A line's quantity may be entered in a convertible unit (ml, cl, l, g, kg): the cost is then worked on
 * the converted quantity, so 75 cl of a wine costing 4.00/l is 3.00.
 */
class RecipeCost
{
    /** @var array<string, int> lot id → cost per litre, minor units */
    private array $perLiter = [];

    public function __construct(private readonly LotCostService $lots) {}

    /**
     * @param  iterable<RecipeItem>  $recipe  input and wineLot relations loaded
     * @return list<array{kind: string, unit: ?string, base_quantity: float, unit_cost: ?array{minor: float, currency: string}, line_cost: ?Money}>
     */
    public function lines(iterable $recipe): array
    {
        $out = [];

        foreach ($recipe as $line) {
            $out[] = $this->line($line);
        }

        return $out;
    }

    /**
     * The recipe's cost for one unit of output. `complete` is false when any line has no cost, in which
     * case the total understates the real figure.
     *
     * @param  iterable<RecipeItem>  $recipe
     * @return array{total: ?Money, complete: bool}
     */
    public function total(iterable $recipe): array
    {
        $minor = 0;
        $currency = null;
        $complete = true;
        $any = false;

        foreach ($this->lines($recipe) as $line) {
            if ($line['line_cost'] === null) {
                $complete = false;

                continue;
            }

            $any = true;
            $currency ??= $line['line_cost']->getCurrencyCode();
            $minor += $line['line_cost']->getMinorAmount();
        }

        return ['total' => $any && $currency !== null ? Money::fromMinor($minor, $currency) : null, 'complete' => $complete && $any];
    }

    /**
     * @return array{kind: string, unit: ?string, base_quantity: float, unit_cost: ?array{minor: float, currency: string}, line_cost: ?Money}
     */
    private function line(RecipeItem $line): array
    {
        $quantity = (float) $line->quantity;
        $input = $line->input;

        if ($input instanceof InventoryItem) {
            $cost = $input->cost_per_unit;
            $factor = Measure::factor($line->unit, $input->unit) ?? 1.0;
            // A raw material bought by the pack (12 corks in a box) is costed per pack: one unit is its share.
            $perUnit = $cost === null ? null : $cost->getMinorAmount() / max(1, (int) $input->pack_size);

            return $this->priced('catalog', $line->unit, $quantity, $factor, $perUnit, $cost?->getCurrencyCode());
        }

        $lot = $line->wineLot;

        if ($lot instanceof WineLot) {
            $factor = Measure::factor($line->unit ?? 'l', 'l') ?? 1.0;

            return $this->priced('cellar', $line->unit ?? 'l', $quantity, $factor, $this->perLiter($lot), $this->currency($line));
        }

        // Custom — or a cellar line whose lot has gone, which keeps its last per-litre cost.
        $cost = $line->custom_cost;
        $factor = Measure::factor($line->unit, $line->custom_unit) ?? 1.0;

        return $this->priced('custom', $line->unit, $quantity, $factor, $cost?->getMinorAmount(), $cost?->getCurrencyCode());
    }

    /**
     * @return array{kind: string, unit: ?string, base_quantity: float, unit_cost: ?array{minor: float, currency: string}, line_cost: ?Money}
     */
    private function priced(string $kind, ?string $unit, float $quantity, float $factor, int|float|null $perBaseUnitMinor, ?string $currency): array
    {
        $base = $quantity * $factor;

        if ($perBaseUnitMinor === null || $currency === null) {
            return ['kind' => $kind, 'unit' => $unit, 'base_quantity' => $base, 'unit_cost' => null, 'line_cost' => null];
        }

        return [
            'kind' => $kind,
            'unit' => $unit,
            'base_quantity' => $base,
            // What one of the line's own unit (1 ml) costs — a fraction of a minor unit is normal.
            'unit_cost' => ['minor' => $perBaseUnitMinor * $factor, 'currency' => $currency],
            'line_cost' => Money::fromMinor((int) round($perBaseUnitMinor * $base), $currency),
        ];
    }

    private function perLiter(WineLot $lot): int
    {
        return $this->perLiter[$lot->getKey()] ??= $this->lots->breakdown($lot)['per_liter'];
    }

    private function currency(RecipeItem $line): string
    {
        return $line->tenant?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
