<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

class SetRecipeAction
{
    /**
     * Replace an item's recipe with the given input lines.
     *
     * @param  list<array{input_id: string, quantity: string}>  $lines
     * @return list<array{input_id: string, quantity: string}>
     */
    public function execute(InventoryItem $output, array $lines): array
    {
        return DB::transaction(function () use ($output, $lines): array {
            $output->recipe()->delete();

            foreach ($lines as $line) {
                RecipeItem::create([
                    'output_id' => $output->getKey(),
                    'input_id' => $line['input_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            return array_values($output->recipe()->get()
                ->map(fn (RecipeItem $r) => ['input_id' => (string) $r->input_id, 'quantity' => (string) $r->quantity])
                ->all());
        });
    }
}
