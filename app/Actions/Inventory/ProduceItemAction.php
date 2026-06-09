<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Produce finished/semi-finished goods from an item's recipe: consume each
 * catalog-backed input (PRODUCTION_OUT, guarded) and add the output
 * (PRODUCTION_IN), in one transaction. Custom (non-catalog) recipe lines carry
 * cost only and are not consumed from stock.
 */
class ProduceItemAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(InventoryItem $output, string $displayQuantity): InventoryItemData
    {
        /** @var Collection<int, RecipeItem> $lines */
        $lines = $output->recipe()->whereNotNull('input_id')->with('input')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'display_quantity' => 'This item has no recipe to produce from.',
            ]);
        }

        $reference = 'PROD-'.$output->sku;

        DB::transaction(function () use ($output, $displayQuantity, $lines, $reference): void {
            foreach ($lines as $line) {
                $input = $line->input;
                if (! $input instanceof InventoryItem) {
                    continue;
                }

                $needed = Quantity::mul((string) $line->quantity, $displayQuantity);
                $this->ledger->withdraw($input, $needed, StockMovementType::ProductionOut, $reference);
            }

            $this->ledger->record(
                $output,
                StockMovementType::ProductionIn,
                Quantity::normalize($displayQuantity),
                $reference,
            );
        });

        return InventoryItemData::fromModel($output->refresh());
    }
}
