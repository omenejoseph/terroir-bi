<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;
use Illuminate\Support\Facades\DB;

/**
 * Apply one addition (typically the same product/dose) to several lots in one
 * transaction. Each line names its lot and quantity; the shared product fields
 * are reused. Stock deduction is handled per line by AddCellarAdditionAction.
 */
class BulkAddAdditionsAction
{
    public function __construct(private readonly AddCellarAdditionAction $add) {}

    /**
     * @param  array<string, mixed>  $data  {name, unit, category?, enological_product_id?, cost_per_unit?, lots: [{wine_lot_id, quantity}]}
     * @return int number of additions recorded
     */
    public function execute(array $data, string $createdById): int
    {
        /** @var list<array<string, mixed>> $lots */
        $lots = $data['lots'] ?? [];

        return DB::transaction(function () use ($data, $lots, $createdById): int {
            $count = 0;
            foreach ($lots as $line) {
                $lot = WineLot::query()->whereKey((string) $line['wine_lot_id'])->firstOrFail();
                $this->add->execute($lot, [
                    'name' => $data['name'],
                    'category' => $data['category'] ?? null,
                    'unit' => $data['unit'],
                    'quantity' => $line['quantity'],
                    'cost_per_unit' => $data['cost_per_unit'] ?? null,
                    'enological_product_id' => $data['enological_product_id'] ?? null,
                    'note' => $data['note'] ?? null,
                ], $createdById);
                $count++;
            }

            return $count;
        });
    }
}
