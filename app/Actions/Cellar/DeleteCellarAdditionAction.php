<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/** Delete an addition, restoring any enological stock it consumed. */
class DeleteCellarAdditionAction
{
    public function execute(CellarAddition $addition): void
    {
        DB::transaction(function () use ($addition): void {
            if ($addition->enological_product_id !== null) {
                $product = EnologicalProduct::query()
                    ->whereKey($addition->enological_product_id)
                    ->lockForUpdate()
                    ->first();
                if ($product !== null) {
                    $product->current_stock = Quantity::add(
                        (string) $product->current_stock,
                        (string) $addition->quantity,
                    );
                    $product->save();
                }
            }

            $addition->delete();
        });
    }
}
