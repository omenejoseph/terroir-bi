<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\GrapeContractStatus;
use App\Models\GrapeContract;

/**
 * Aggregate a cooperant supplier's delivery performance across their grape
 * contracts: fulfilment %, reliability % (fulfilled / total), and the contracted
 * vs delivered tonnage.
 */
class GrowerPerformanceQuery
{
    /**
     * @return array{contracts: int, estimated_kg: float, delivered_kg: float, fulfillment_pct: float, reliability_pct: float}
     */
    public function forSupplier(string $supplierId): array
    {
        $contracts = GrapeContract::query()->where('supplier_id', $supplierId)->get();

        $estimated = (float) $contracts->sum(fn (GrapeContract $c) => (float) $c->estimated_kg);
        $delivered = (float) $contracts->sum(fn (GrapeContract $c) => (float) $c->delivered_kg);
        $fulfilled = $contracts->filter(fn (GrapeContract $c) => $c->status === GrapeContractStatus::Fulfilled)->count();

        return [
            'contracts' => $contracts->count(),
            'estimated_kg' => round($estimated, 3),
            'delivered_kg' => round($delivered, 3),
            'fulfillment_pct' => $estimated > 0 ? round($delivered / $estimated * 100, 1) : 0.0,
            'reliability_pct' => $contracts->count() > 0 ? round($fulfilled / $contracts->count() * 100, 1) : 0.0,
        ];
    }
}
