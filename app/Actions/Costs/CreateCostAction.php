<?php

declare(strict_types=1);

namespace App\Actions\Costs;

use App\Enums\CostStatus;
use App\Models\Cost;
use Illuminate\Support\Facades\DB;

class CreateCostAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function execute(array $attributes, array $items, string $createdById): Cost
    {
        return DB::transaction(function () use ($attributes, $items, $createdById): Cost {
            $attributes['created_by_id'] = $createdById;
            $attributes['date'] ??= now();

            $cost = Cost::create($attributes);

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

            if ($cost->status === CostStatus::Paid && $cost->paid_at === null) {
                $cost->paid_at = now();
                $cost->save();
            }

            return $cost;
        });
    }
}
