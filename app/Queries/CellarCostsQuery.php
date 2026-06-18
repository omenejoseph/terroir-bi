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
                    'cost' => $this->costs->breakdown($lot),
                ];
            })
            ->all();

        return array_values($rows);
    }
}
