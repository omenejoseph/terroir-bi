<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Apply one addition (typically the same product/dose) to several lots in one
 * transaction. Each line names its lot and quantity; the shared product fields
 * are reused. Stock deduction is handled per line by AddCellarAdditionAction.
 */
class BulkAddAdditionsAction
{
    public function __construct(
        private readonly AddCellarAdditionAction $add,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {name, unit, category?, enological_product_id?, cost_per_unit?, lots: [{wine_lot_id, quantity, vessel_id?, note?}]}
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
                    'vessel_id' => $line['vessel_id'] ?? null,
                    'cost_per_unit' => $data['cost_per_unit'] ?? null,
                    'enological_product_id' => $data['enological_product_id'] ?? null,
                    'note' => $line['note'] ?? $data['note'] ?? null,
                ], $createdById);
                $count++;
            }

            // One summary entry for the batch, alongside each addition's own entry.
            $this->audit->record(Auth::user(), 'cellar.bulk_additions_applied', null, [
                'name' => $data['name'],
                'enological_product_id' => $data['enological_product_id'] ?? null,
                'unit' => $data['unit'],
                'lines' => array_map(fn (array $l): array => [
                    'wine_lot_id' => $l['wine_lot_id'],
                    'vessel_id' => $l['vessel_id'] ?? null,
                    'quantity' => (string) $l['quantity'],
                ], $lots),
            ]);

            return $count;
        });
    }
}
