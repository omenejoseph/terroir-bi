<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\WineLotStatus;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Services\Cellar\LotNumberGenerator;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Create a wine lot: assign a tenant-scoped lot number, record its grape
 * composition, derive grape cost (price/kg × weight), and optionally place its
 * full initial volume into a starting vessel — all in one transaction. Money
 * columns are stored as integer minor units (MoneyCast resolves the currency).
 */
class CreateWineLotAction
{
    public function __construct(
        private readonly LotNumberGenerator $numbers,
        private readonly LotVolumeService $volumes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): WineLot
    {
        return DB::transaction(function () use ($data): WineLot {
            $initialVolume = Quantity::normalize((string) $data['initial_volume']);

            $pricePerKg = isset($data['grape_price_per_kg']) ? (int) $data['grape_price_per_kg'] : null;
            $grapeCost = null;
            if ($pricePerKg !== null && isset($data['harvest_weight_kg'])) {
                $grapeCost = (int) round($pricePerKg * (float) $data['harvest_weight_kg']);
            }

            $lot = WineLot::create([
                'lot_number' => $this->numbers->next(),
                'name' => $data['name'],
                'grape_variety' => $data['grape_variety'],
                'vintage' => (string) $data['vintage'],
                'vineyard' => $data['vineyard'] ?? null,
                'wine_type' => $data['wine_type'] ?? null,
                'initial_volume' => $initialVolume,
                'current_volume' => $initialVolume,
                'status' => $data['status'] ?? WineLotStatus::Fermenting->value,
                'grape_cost' => $grapeCost,
                'grape_price_per_kg' => $pricePerKg,
                'harvest_weight_kg' => $data['harvest_weight_kg'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            /** @var list<array<string, mixed>> $grapes */
            $grapes = $data['grapes'] ?? [];
            foreach ($grapes as $grape) {
                $lot->grapes()->create([
                    'grape_variety' => $grape['grape_variety'],
                    'percentage' => $grape['percentage'] ?? null,
                    'price_per_kg' => isset($grape['price_per_kg']) ? (int) $grape['price_per_kg'] : null,
                    'weight_kg' => $grape['weight_kg'] ?? null,
                ]);
            }

            if (! empty($data['vessel_id'])) {
                $vessel = Vessel::query()->whereKey((string) $data['vessel_id'])->firstOrFail();
                $this->volumes->assignToVessel($lot, $vessel, $initialVolume);
            }

            return $lot;
        });
    }
}
