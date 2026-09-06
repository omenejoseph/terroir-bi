<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\WineLot;
use App\Services\Cellar\LotCostService;

/** Per-lot cost roll-up (grape + additions) for the Wine Costs page. */
class CellarCostsQuery
{
    public function __construct(private readonly LotCostService $costs) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $rows = WineLot::query()
            ->where('status', '!=', WineLotStatus::Bottled)
            // One aggregate query for every lot's additions total, instead of
            // LotCostService::breakdown() running its own additions() query
            // per lot below.
            ->withSum('additions as additions_total', 'total_cost')
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get()
            ->map(function (WineLot $lot): array {
                return [
                    'id' => $lot->getKey(),
                    'lot_number' => $lot->lot_number,
                    'name' => $lot->name,
                    'vintage' => $lot->vintage,
                    'current_volume' => (string) $lot->current_volume,
                    'cost' => $this->costs->breakdown($lot, (int) ($lot->getAttribute('additions_total') ?? 0)),
                ];
            })
            ->all();

        return array_values($rows);
    }
}
