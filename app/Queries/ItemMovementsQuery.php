<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\StockMovementData;
use App\Models\InventoryItem;

/**
 * An item's ledger entries, newest first, each carrying the running stock
 * balance *after* that movement.
 *
 * The balance is not stored — it is reconstructed by walking back from the
 * item's current stock, which is what the design's "Movement history" table
 * shows ("stock rebuild then drain", Figma 449:1577). Deriving it here rather
 * than in a controller keeps the API and the Inertia page on one definition.
 */
class ItemMovementsQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function get(InventoryItem $item, int $limit = 100): array
    {
        // ULIDs sort by creation, so id ordering is chronological.
        $movements = $item->stockMovements()
            ->with('createdBy')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $balance = (float) $item->current_stock;
        $out = [];

        foreach ($movements as $movement) {
            $row = StockMovementData::fromModel($movement)->toArray();

            // The newest movement lands on today's stock; each older one lands on
            // the balance before the movement that followed it.
            $row['balance'] = $this->format($balance);
            $out[] = $row;

            $balance -= (float) $movement->quantity;
        }

        return $out;
    }

    /** Match the decimal:3 precision the quantity columns use, without trailing noise. */
    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
