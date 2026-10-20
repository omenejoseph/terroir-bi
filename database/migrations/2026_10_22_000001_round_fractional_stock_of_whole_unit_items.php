<?php

declare(strict_types=1);

use App\Enums\StockMovementType;
use App\Support\StockUnits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bottles, cases and units are counted in whole pieces (see StockUnits), but older data and imports
 * could leave a fractional on-hand such as 1.3 bottles. Round those to the nearest whole piece and
 * write the difference as a reconciliation ADJUSTMENT, so the ledger still adds up to the stock and
 * the change is visible on the item. Minimum stock levels are rounded the same way (no ledger needed).
 *
 * Idempotent: once an item is whole there is nothing left to select.
 */
return new class extends Migration
{
    public function up(): void
    {
        $units = ['bottle', 'bottles', 'case', 'cases', 'unit', 'units'];

        DB::table('inventory_items')
            ->whereIn(DB::raw('lower(trim(unit))'), $units)
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    if (! StockUnits::isWhole($item->unit)) {
                        continue;
                    }

                    $stock = (float) $item->current_stock;
                    $whole = round($stock);
                    $update = [];

                    if ($stock !== $whole) {
                        $difference = number_format($whole - $stock, 3, '.', '');
                        $update['current_stock'] = number_format($whole, 3, '.', '');

                        DB::table('stock_movements')->insert([
                            'id' => (string) Str::ulid(),
                            'tenant_id' => $item->tenant_id,
                            'inventory_item_id' => $item->id,
                            'type' => StockMovementType::Adjustment->value,
                            'quantity' => $difference,
                            'unit' => $item->unit,
                            'note' => 'Rounded to a whole unit',
                            'reference' => 'WHOLE-UNITS-FIX',
                            'is_reconciliation' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if ($item->min_stock !== null && (float) $item->min_stock !== round((float) $item->min_stock)) {
                        $update['min_stock'] = number_format(round((float) $item->min_stock), 3, '.', '');
                    }

                    if ($update !== []) {
                        DB::table('inventory_items')->where('id', $item->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        // The original fractions are not recoverable, and a whole number is never wrong to keep.
    }
};
