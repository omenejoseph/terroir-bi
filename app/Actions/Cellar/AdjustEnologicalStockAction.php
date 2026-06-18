<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Apply a signed stock adjustment to an enological product. */
class AdjustEnologicalStockAction
{
    public function execute(EnologicalProduct $product, string $delta): EnologicalProduct
    {
        return DB::transaction(function () use ($product, $delta): EnologicalProduct {
            /** @var EnologicalProduct $product */
            $product = EnologicalProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $next = Quantity::add((string) $product->current_stock, Quantity::normalize($delta));
            if (Quantity::compare($next, '0.000') < 0) {
                throw ValidationException::withMessages([
                    'delta' => 'Adjustment would drive stock negative.',
                ]);
            }

            $product->current_stock = $next;
            $product->save();

            return $product;
        });
    }
}
