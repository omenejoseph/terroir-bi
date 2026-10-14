<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\So2DosePlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Apply now" on the SO₂ screen: recompute every dose on the server, then
 * record one addition per vessel (or lot) and draw the product's stock — all
 * or nothing, and refused when the stock does not cover the total.
 */
class ApplySo2Action
{
    public function __construct(
        private readonly So2DosePlanner $planner,
        private readonly BulkAddAdditionsAction $bulk,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{enological_product_id: string, mode?: string|null, note?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>}  $request
     * @return array<string, mixed> the applied plan (lines + totals)
     */
    public function execute(array $request, string $userId): array
    {
        return DB::transaction(function () use ($request, $userId): array {
            $plan = $this->planner->plan($request);

            if ($plan['lines'] === []) {
                throw ValidationException::withMessages(['items' => 'None of the selected wines needs SO₂ at these levels.']);
            }

            /** @var EnologicalProduct $product */
            $product = EnologicalProduct::query()->whereKey($request['enological_product_id'])->firstOrFail();
            $userNote = isset($request['note']) && trim((string) $request['note']) !== '' ? trim((string) $request['note']) : null;

            $this->bulk->execute([
                'name' => $product->name,
                'category' => $product->category,
                'unit' => $product->unit,
                'cost_per_unit' => $product->cost_per_unit?->getMinorAmount(),
                'enological_product_id' => $product->getKey(),
                'lots' => array_map(fn (array $line): array => [
                    'wine_lot_id' => $line['wine_lot_id'],
                    'vessel_id' => $line['vessel_id'],
                    'quantity' => $line['quantity'],
                    'note' => implode(' · ', array_filter([$userNote, $line['note']])),
                ], $plan['lines']),
            ], $userId);

            $this->audit->record(Auth::user(), 'cellar.so2_applied', $product, [
                'lines' => array_map(fn (array $l): array => [
                    'wine_lot_id' => $l['wine_lot_id'],
                    'vessel_id' => $l['vessel_id'],
                    'quantity' => $l['quantity'],
                    'add_mg_l' => $l['add_mg_l'],
                ], $plan['lines']),
                'total' => $plan['totals']['quantity'],
                'unit' => $product->unit,
            ]);

            return $plan;
        });
    }
}
