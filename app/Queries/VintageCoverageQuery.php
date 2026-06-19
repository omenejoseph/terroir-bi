<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Vintage-transition coverage for a wine. Customers buy e.g. "R3" largely
 * regardless of vintage, so a wine's true runway is the sum of stock across all
 * its in-stock vintages, depleting at the blended velocity. This surfaces every
 * vintage (same product name, finished goods) with its own days-of-stock-left,
 * plus a FIFO transition read: the oldest in-stock vintage is sold down first,
 * and we check whether a newer vintage is ready before it runs out.
 *
 * Returns null for single-vintage wines (nothing to transition). Velocity uses
 * the same trailing-window exit definition as the per-item stock analytics
 * (negative movements), expressed in bottle-equivalents.
 */
class VintageCoverageQuery
{
    private const WINDOW_DAYS = 90;

    /**
     * @return array<string, mixed>|null
     */
    public function get(InventoryItem $item): ?array
    {
        $siblings = InventoryItem::query()
            ->where('name', $item->name)
            ->where('category', InventoryCategory::Finished->value)
            ->get(['id', 'vintage', 'unit', 'bottles_per_case', 'current_stock']);

        if ($siblings->count() < 2) {
            return null; // single-vintage wine — nothing to transition
        }

        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        $exits = StockMovement::query()
            ->whereIn('inventory_item_id', $siblings->pluck('id')->all())
            ->where('quantity', '<', 0)
            ->where('created_at', '>=', $since)
            ->get(['inventory_item_id', 'quantity']);

        $displayUnit = 'bottles';
        $rows = $siblings->map(function (InventoryItem $s) use ($exits, $item, &$displayUnit): array {
            $bpc = max(1, (int) $s->bottles_per_case);
            $isCases = in_array(strtolower((string) $s->unit), ['case', 'cases'], true);
            if (! $isCases) {
                $displayUnit = (string) $s->unit;
            }

            $stockBE = (int) round($isCases ? (float) $s->current_stock * $bpc : (float) $s->current_stock);
            $exitBE = $exits
                ->where('inventory_item_id', $s->getKey())
                ->sum(fn (StockMovement $m): float => $isCases ? abs((float) $m->quantity) * $bpc : abs((float) $m->quantity));
            $velocity = round($exitBE / self::WINDOW_DAYS, 2);
            $daysLeft = $velocity > 0 && $stockBE > 0 ? round($stockBE / $velocity, 1) : null;

            return [
                'inventory_item_id' => (string) $s->getKey(),
                'vintage' => $s->vintage,
                'stock_bottles' => $stockBE,
                'velocity_per_day' => $velocity,
                'days_left' => $daysLeft,
                'is_current' => $s->getKey() === $item->getKey(),
            ];
        })->all();

        // Newest vintage first; NV / non-numeric treated as newest.
        $vintageNum = static fn (?string $v): int => $v !== null && preg_match('/^\d{4}$/', $v) === 1 ? (int) $v : PHP_INT_MAX;
        usort($rows, fn (array $a, array $b) => $vintageNum($b['vintage']) <=> $vintageNum($a['vintage']));

        $totalStock = array_sum(array_column($rows, 'stock_bottles'));
        $blendedVelocity = round((float) array_sum(array_column($rows, 'velocity_per_day')), 2);
        $combinedDaysLeft = $blendedVelocity > 0 && $totalStock > 0
            ? round($totalStock / $blendedVelocity, 1)
            : null;

        // FIFO read: oldest in-stock vintage is being sold down; its successor is
        // the next-newer vintage that still has stock.
        $inStock = array_values(array_filter($rows, fn (array $r) => $r['stock_bottles'] > 0));
        $selling = $inStock !== [] ? $inStock[count($inStock) - 1] : null; // rows are newest-first
        $successorVintage = null;
        if ($selling !== null) {
            $sellingIdx = (int) array_search($selling['inventory_item_id'], array_column($rows, 'inventory_item_id'), true);
            for ($i = $sellingIdx - 1; $i >= 0; $i--) {
                if ($rows[$i]['stock_bottles'] > 0) {
                    $successorVintage = $rows[$i]['vintage'];
                    break;
                }
            }
        }

        $sellingDaysLeft = $selling['days_left'] ?? null;
        $gapRisk = $selling !== null
            && $sellingDaysLeft !== null
            && $sellingDaysLeft < 30
            && $successorVintage === null
            && count($rows) > 1;

        return [
            'wine_name' => $item->name,
            'display_unit' => $displayUnit,
            'vintages' => $rows,
            'total_stock_bottles' => $totalStock,
            'blended_velocity_per_day' => $blendedVelocity,
            'combined_days_left' => $combinedDaysLeft,
            'selling_vintage' => $selling['vintage'] ?? null,
            'successor_vintage' => $successorVintage,
            'selling_days_left' => $sellingDaysLeft,
            'gap_risk' => $gapRisk,
            'window_days' => self::WINDOW_DAYS,
        ];
    }
}
