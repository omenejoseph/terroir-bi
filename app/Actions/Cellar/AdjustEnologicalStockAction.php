<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\EnologicalProduct;
use App\Services\Cellar\EnologicalStockLedger;

/** Apply a signed manual stock adjustment (with an optional note) to an enological product. */
class AdjustEnologicalStockAction
{
    public function __construct(private readonly EnologicalStockLedger $ledger) {}

    public function execute(EnologicalProduct $product, string $delta, ?string $note = null): EnologicalProduct
    {
        return $this->ledger->move($product, $delta, EnologicalStockSource::Manual, null, $note, 'delta');
    }
}
