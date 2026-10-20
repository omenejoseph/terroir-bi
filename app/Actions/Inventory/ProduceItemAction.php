<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Services\Inventory\StockLedger;
use App\Support\Measure;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Produce finished/semi-finished goods from an item's recipe: consume each
 * catalog-backed input (PRODUCTION_OUT, guarded) and add the output
 * (PRODUCTION_IN), in one transaction. Custom and cellar-wine recipe lines carry
 * cost only and are not consumed from stock.
 */
class ProduceItemAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(InventoryItem $output, string $displayQuantity): InventoryItemData
    {
        /** @var Collection<int, RecipeItem> $recipe */
        $recipe = $output->recipe()->with('input')->get();

        // Only catalog lines are drawn from stock; a recipe of cellar wine and custom lines alone just
        // assembles the output.
        $lines = $recipe->whereNotNull('input_id');

        if ($recipe->isEmpty()) {
            throw ValidationException::withMessages([
                'display_quantity' => 'This item has no recipe to produce from.',
            ]);
        }

        $needs = [];
        foreach ($lines as $line) {
            $input = $line->input;
            if ($input instanceof InventoryItem) {
                $needs[] = [$input, $this->needed($line, $input, $displayQuantity)];
            }
        }

        // Check every input up front so the message lists everything that is short, not just the first.
        $short = [];
        foreach ($needs as [$input, $needed]) {
            if (Quantity::compare((string) $input->current_stock, $needed) < 0) {
                $short[] = "{$input->name} — {$input->current_stock} {$input->unit} in stock, {$needed} needed";
            }
        }

        if ($short !== []) {
            throw ValidationException::withMessages([
                'display_quantity' => __('Not enough stock to produce :quantity: :short.', ['quantity' => $displayQuantity, 'short' => implode('; ', $short)]),
            ]);
        }

        $reference = 'PROD-'.$output->sku;

        DB::transaction(function () use ($output, $displayQuantity, $needs, $reference): void {
            foreach ($needs as [$input, $needed]) {
                $this->ledger->withdraw($input, $needed, StockMovementType::ProductionOut, $reference);
            }

            $this->ledger->record(
                $output,
                StockMovementType::ProductionIn,
                Quantity::normalize($displayQuantity),
                $reference,
            );

            // The run itself, so the Produce tab can say what was made and what it took.
            $output->productionRuns()->create([
                'quantity' => Quantity::normalize($displayQuantity),
                'reference' => $reference,
                'inputs' => array_map(fn (array $need): array => [
                    'name' => $need[0]->name,
                    'sku' => $need[0]->sku,
                    'unit' => $need[0]->unit,
                    'quantity' => $need[1],
                ], $needs),
                'created_by_id' => auth()->id(),
            ]);
        });

        return InventoryItemData::fromModel($output->refresh());
    }

    /** What one production run takes of an input, in the input's own unit. */
    private function needed(RecipeItem $line, InventoryItem $input, string $displayQuantity): string
    {
        // A line entered in ml / cl / g is consumed in the input's own unit (litres, kg). Multiplying
        // before rounding keeps 0.5 ml × 1000 bottles at 0.5 l instead of rounding the ml first.
        $factor = Measure::factor($line->unit, $input->unit);

        return $factor === null
            ? Quantity::mul((string) $line->quantity, $displayQuantity)
            : Quantity::normalize(number_format((float) $line->quantity * $factor * (float) $displayQuantity, 3, '.', ''));
    }
}
