<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\CustomerProductOverride;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Folds duplicate customers into a winner: orders move over wholesale; per-item
 * rows (prices, catalog overrides) move too, except where the winner already has
 * a row for that item — those loser rows are dropped so the unique
 * (item, customer) constraint holds. Losers are then deleted.
 */
class CustomerMergeService
{
    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    public function preview(Customer $winner, array $loserIds): array
    {
        return $this->run($winner, $loserIds, apply: false);
    }

    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    public function merge(Customer $winner, array $loserIds): array
    {
        return DB::transaction(fn (): array => $this->run($winner, $loserIds, apply: true));
    }

    /**
     * @param  list<string>  $loserIds
     * @return array<string, mixed>
     */
    private function run(Customer $winner, array $loserIds, bool $apply): array
    {
        $losers = Customer::query()
            ->whereIn('id', $loserIds)
            ->where('id', '!=', $winner->getKey())
            ->get();

        // The winner's existing per-item rows; grows as loser rows are reassigned.
        $winnerPricedItems = array_values(array_map('strval', CustomerPrice::query()->where('customer_id', $winner->getKey())->pluck('inventory_item_id')->all()));
        $winnerOverrideItems = array_values(array_map('strval', CustomerProductOverride::query()->where('customer_id', $winner->getKey())->pluck('inventory_item_id')->all()));

        $perLoser = [];
        $totals = ['orders' => 0, 'price_reassign' => 0, 'price_drop' => 0, 'override_reassign' => 0, 'override_drop' => 0];

        foreach ($losers as $loser) {
            $orders = Order::query()->where('customer_id', $loser->getKey())->count();
            if ($apply && $orders > 0) {
                Order::query()->where('customer_id', $loser->getKey())->update(['customer_id' => $winner->getKey()]);
            }

            $price = $this->foldPerItem(CustomerPrice::query()->where('customer_id', $loser->getKey())->get(), $winnerPricedItems, $winner, $apply);
            $override = $this->foldPerItem(CustomerProductOverride::query()->where('customer_id', $loser->getKey())->get(), $winnerOverrideItems, $winner, $apply);

            if ($apply) {
                $loser->delete();
            }

            $perLoser[] = [
                'id' => $loser->getKey(),
                'company_name' => $loser->company_name,
                'orders' => $orders,
                'price_reassign' => $price['reassign'],
                'price_drop' => $price['drop'],
                'override_reassign' => $override['reassign'],
                'override_drop' => $override['drop'],
            ];

            $totals['orders'] += $orders;
            $totals['price_reassign'] += $price['reassign'];
            $totals['price_drop'] += $price['drop'];
            $totals['override_reassign'] += $override['reassign'];
            $totals['override_drop'] += $override['drop'];
        }

        return [
            'applied' => $apply,
            'winner' => ['id' => $winner->getKey(), 'company_name' => $winner->company_name],
            'losers' => $perLoser,
            'totals' => $totals + ['losers_deleted' => $losers->count()],
        ];
    }

    /**
     * Reassign per-item rows to the winner, dropping collisions on items the
     * winner already owns. Mutates $winnerItems with newly-owned item ids.
     *
     * @param  iterable<int, CustomerPrice|CustomerProductOverride>  $rows
     * @param  list<string>  $winnerItems
     * @return array{reassign: int, drop: int}
     */
    private function foldPerItem(iterable $rows, array &$winnerItems, Customer $winner, bool $apply): array
    {
        $reassign = 0;
        $drop = 0;

        foreach ($rows as $row) {
            if (in_array($row->inventory_item_id, $winnerItems, true)) {
                $drop++;
                if ($apply) {
                    $row->delete();
                }

                continue;
            }

            $reassign++;
            $winnerItems[] = $row->inventory_item_id;
            if ($apply) {
                $row->customer_id = $winner->getKey();
                $row->save();
            }
        }

        return ['reassign' => $reassign, 'drop' => $drop];
    }
}
