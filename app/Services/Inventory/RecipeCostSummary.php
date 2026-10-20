<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Support\Money\Money;

/**
 * The Recipe tab's header: what one unit of the item costs to make from its recipe, next to what it
 * sells for. The recipe is written per bottle; an item stocked in cases is a case's worth of bottles.
 */
class RecipeCostSummary
{
    public function __construct(private readonly RecipeCost $cost) {}

    /**
     * @return array{per_unit: ?array<string, mixed>, complete: bool, multiplier: int}
     */
    public function for(InventoryItem $item): array
    {
        ['total' => $total, 'complete' => $complete] = $this->cost->total($item->recipe()->with(['input', 'wineLot'])->get());

        $multiplier = in_array(strtolower($item->unit), ['case', 'cases'], true) ? max(1, (int) $item->bottles_per_case) : 1;

        return [
            'per_unit' => $total === null ? null : Money::fromMinor($total->getMinorAmount() * $multiplier, $total->getCurrencyCode())->jsonSerialize(),
            'complete' => $complete,
            'multiplier' => $multiplier,
        ];
    }
}
