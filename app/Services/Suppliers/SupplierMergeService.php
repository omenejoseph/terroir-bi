<?php

declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Models\Cost;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierPriceChange;
use App\Models\SupplierPriceItem;
use Illuminate\Support\Facades\DB;

/**
 * Folds duplicate suppliers into a winner: purchase orders, costs and the
 * price-change log move over wholesale; price-list lines move too, except where
 * the winner already has a line with the same description — those loser lines
 * are dropped (the price list is keyed on description). Losers are then deleted.
 */
class SupplierMergeService
{
    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    public function preview(Supplier $winner, array $loserIds): array
    {
        return $this->run($winner, $loserIds, apply: false);
    }

    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    public function merge(Supplier $winner, array $loserIds): array
    {
        return DB::transaction(fn (): array => $this->run($winner, $loserIds, apply: true));
    }

    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    private function run(Supplier $winner, array $loserIds, bool $apply): array
    {
        $losers = Supplier::query()
            ->whereIn('id', $loserIds)
            ->where('id', '!=', $winner->getKey())
            ->get();

        // The winner's existing price-list descriptions; grows as loser lines move.
        $winnerDescriptions = array_values(array_map(
            'strval',
            SupplierPriceItem::query()->where('supplier_id', $winner->getKey())->pluck('description')->all(),
        ));

        $perLoser = [];
        $totals = ['orders' => 0, 'costs' => 0, 'price_reassign' => 0, 'price_drop' => 0];

        foreach ($losers as $loser) {
            $orders = SupplierOrder::query()->where('supplier_id', $loser->getKey())->count();
            $costs = Cost::query()->where('supplier_id', $loser->getKey())->count();

            if ($apply) {
                SupplierOrder::query()->where('supplier_id', $loser->getKey())->update(['supplier_id' => $winner->getKey()]);
                Cost::query()->where('supplier_id', $loser->getKey())->update(['supplier_id' => $winner->getKey()]);
                SupplierPriceChange::query()->where('supplier_id', $loser->getKey())->update(['supplier_id' => $winner->getKey()]);
            }

            $price = $this->foldPriceItems(
                SupplierPriceItem::query()->where('supplier_id', $loser->getKey())->get(),
                $winnerDescriptions,
                $winner,
                $apply,
            );

            if ($apply) {
                $loser->delete();
            }

            $perLoser[] = [
                'id' => $loser->getKey(),
                'company_name' => $loser->company_name,
                'orders' => $orders,
                'costs' => $costs,
                'price_reassign' => $price['reassign'],
                'price_drop' => $price['drop'],
            ];

            $totals['orders'] += $orders;
            $totals['costs'] += $costs;
            $totals['price_reassign'] += $price['reassign'];
            $totals['price_drop'] += $price['drop'];
        }

        return [
            'applied' => $apply,
            'winner' => ['id' => $winner->getKey(), 'company_name' => $winner->company_name],
            'losers' => $perLoser,
            'totals' => $totals + ['losers_deleted' => $losers->count()],
        ];
    }

    /**
     * Move price-list lines to the winner, dropping collisions on descriptions
     * the winner already has. Mutates $winnerDescriptions with newly-owned ones.
     *
     * @param  iterable<int, SupplierPriceItem>  $rows
     * @param  list<string>  $winnerDescriptions
     * @return array{reassign: int, drop: int}
     */
    private function foldPriceItems(iterable $rows, array &$winnerDescriptions, Supplier $winner, bool $apply): array
    {
        $reassign = 0;
        $drop = 0;

        foreach ($rows as $row) {
            if (in_array($row->description, $winnerDescriptions, true)) {
                $drop++;
                if ($apply) {
                    $row->delete();
                }

                continue;
            }

            $reassign++;
            $winnerDescriptions[] = $row->description;
            if ($apply) {
                $row->supplier_id = $winner->getKey();
                $row->save();
            }
        }

        return ['reassign' => $reassign, 'drop' => $drop];
    }
}
