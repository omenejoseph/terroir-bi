<?php

declare(strict_types=1);

namespace App\Actions\Costs;

use App\Models\Cost;
use Illuminate\Support\Facades\DB;

class UpdateCostAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $items  When provided, replaces the cost's line items.
     */
    public function execute(Cost $cost, array $attributes, ?array $items = null): Cost
    {
        return DB::transaction(function () use ($cost, $attributes, $items): Cost {
            $cost->fill($attributes)->save();

            if ($items !== null) {
                $cost->items()->delete();
                foreach ($items as $item) {
                    $quantity = (string) ($item['quantity'] ?? '1');
                    $unitPrice = (int) $item['unit_price'];
                    $cost->items()->create([
                        'inventory_item_id' => $item['inventory_item_id'] ?? null,
                        'description' => (string) $item['description'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total' => (int) round($unitPrice * (float) $quantity),
                        'category' => $item['category'] ?? null,
                    ]);
                }
            }

            return $cost;
        });
    }
}
