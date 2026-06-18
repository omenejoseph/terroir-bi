<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;

/** Deactivate a product if it has been used; otherwise delete it. */
class DeleteEnologicalProductAction
{
    public function execute(EnologicalProduct $product): void
    {
        if ($product->cellarAdditions()->exists()) {
            $product->is_active = false;
            $product->save();

            return;
        }

        $product->delete();
    }
}
