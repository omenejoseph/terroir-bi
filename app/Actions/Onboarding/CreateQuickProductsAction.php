<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\InventoryCategory;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The setup "quick product grid": creates finished wines a row at a time with
 * just what an order needs — category, vintage, size, sales unit, list price —
 * and books each row's opening stock through the stock ledger (a MANUAL_IN
 * movement), so the item's history starts with the count rather than with an
 * unexplained number.
 *
 * SKUs aren't asked for: one is made from the name and vintage, kept unique
 * within the tenant. All rows go in one transaction — a failure creates none.
 */
class CreateQuickProductsAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  list<array{name: string, product_category_id?: string|null, vintage?: string|null, unit_size?: string|null, sales_unit: string, bottles_per_case: int, default_price?: int|null, opening_stock?: int|null, is_for_sale?: bool}>  $rows
     * @return int how many products were created
     */
    public function execute(array $rows): int
    {
        return DB::transaction(function () use ($rows): int {
            $taken = [];

            foreach ($rows as $row) {
                $sku = $this->sku($row['name'], $row['vintage'] ?? null, $taken);
                $taken[] = $sku;

                $item = InventoryItem::create([
                    'name' => trim($row['name']),
                    'sku' => $sku,
                    'category' => InventoryCategory::Finished->value,
                    'product_category_id' => $row['product_category_id'] ?? null,
                    'vintage' => $this->blankToNull($row['vintage'] ?? null),
                    'unit_size' => $this->blankToNull($row['unit_size'] ?? null),
                    'unit' => 'bottles',
                    'sales_unit' => $row['sales_unit'],
                    'bottles_per_case' => $row['bottles_per_case'],
                    'default_price' => $row['default_price'] ?? null,
                    'is_for_sale' => $row['is_for_sale'] ?? true,
                ]);

                $stock = (int) ($row['opening_stock'] ?? 0);

                if ($stock > 0) {
                    $this->ledger->record($item, StockMovementType::ManualIn, (string) $stock, null, __('Opening stock'));
                }
            }

            return count($rows);
        });
    }

    /**
     * "Plavac Mali" 2022 → PLAVAC-MALI-2022, with -2, -3 … when that is taken.
     *
     * @param  list<string>  $taken  SKUs already used earlier in this batch
     */
    private function sku(string $name, ?string $vintage, array $taken): string
    {
        $base = Str::upper(Str::slug(trim($name.' '.($vintage ?? '')))) ?: 'PRODUCT';
        $base = Str::limit($base, 60, '');
        $sku = $base;

        for ($n = 2; in_array($sku, $taken, true) || InventoryItem::query()->where('sku', $sku)->exists(); $n++) {
            $sku = "{$base}-{$n}";
        }

        return $sku;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
