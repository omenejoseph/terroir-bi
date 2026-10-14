<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Services\Cellar\EnologicalStockLedger;
use Illuminate\Support\Facades\DB;

/** Delete an addition, giving back any enological stock it consumed. */
class DeleteCellarAdditionAction
{
    public function __construct(private readonly EnologicalStockLedger $ledger) {}

    public function execute(CellarAddition $addition): void
    {
        DB::transaction(function () use ($addition): void {
            if ($addition->enological_product_id !== null
                && EnologicalProduct::query()->whereKey($addition->enological_product_id)->exists()) {
                $this->ledger->move($addition->enological_product_id, (string) $addition->quantity, EnologicalStockSource::AdditionReversed, $addition, 'Addition deleted');
            }

            $addition->delete();
        });
    }
}
