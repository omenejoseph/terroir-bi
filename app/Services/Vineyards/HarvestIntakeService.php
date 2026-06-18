<?php

declare(strict_types=1);

namespace App\Services\Vineyards;

use App\Enums\HarvestEntryStatus;
use App\Enums\WineLotStatus;
use App\Models\HarvestEntry;
use App\Models\Vessel;
use App\Models\VineyardParcel;
use App\Models\WineLot;
use App\Services\Cellar\LotNumberGenerator;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the physical reception of a planned harvest entry: computes the wine
 * volume from the weighed grapes and the plan's yield ratio, mints (or tops up)
 * a wine lot in the Cellar, links the grapes, optionally assigns a vessel, marks
 * the entry HARVESTED, and rolls the delivery into its contract — atomically.
 */
class HarvestIntakeService
{
    public function __construct(
        private readonly LotNumberGenerator $numbers,
        private readonly LotVolumeService $volumes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(HarvestEntry $entry, array $data, string $createdById): WineLot
    {
        if ($entry->status !== HarvestEntryStatus::Planned) {
            throw ValidationException::withMessages([
                'status' => 'Only a planned entry can be received.',
            ]);
        }

        return DB::transaction(function () use ($entry, $data): WineLot {
            $plan = $entry->harvestPlan()->firstOrFail();
            $yieldKg = Quantity::normalize((string) $data['actual_yield_kg']);
            $volume = isset($data['actual_volume_liters'])
                ? Quantity::normalize((string) $data['actual_volume_liters'])
                : Quantity::mul($yieldKg, Quantity::normalize((string) $plan->yield_ratio));

            $pricePerKg = isset($data['grape_price_per_kg']) ? (int) $data['grape_price_per_kg'] : null;
            $grapeCost = $pricePerKg !== null ? (int) round($pricePerKg * (float) $yieldKg) : null;
            $parcel = $entry->parcel; // nullable at runtime (parcel_id is optional)
            $parcelName = $parcel instanceof VineyardParcel ? $parcel->name : null;
            $variety = $data['grape_variety'] ?? $entry->grape_variety
                ?? ($parcel instanceof VineyardParcel ? $parcel->grape_variety : 'Unknown');

            $existingLotId = $data['existing_lot_id'] ?? null;
            if ($existingLotId !== null) {
                /** @var WineLot $lot */
                $lot = WineLot::query()->whereKey((string) $existingLotId)->lockForUpdate()->firstOrFail();
                $lot->current_volume = Quantity::add((string) $lot->current_volume, $volume);
                $lot->initial_volume = Quantity::add((string) $lot->initial_volume, $volume);
                $lot->save();
            } else {
                $lot = WineLot::create([
                    'lot_number' => $this->numbers->next(),
                    'name' => $data['lot_name'] ?? ((string) $variety.' '.$plan->season),
                    'grape_variety' => $variety,
                    'vintage' => $plan->season,
                    'vineyard' => $parcelName,
                    'wine_type' => $data['wine_type'] ?? null,
                    'initial_volume' => $volume,
                    'current_volume' => $volume,
                    'status' => WineLotStatus::Fermenting->value,
                    'grape_cost' => $grapeCost,
                    'grape_price_per_kg' => $pricePerKg,
                    'harvest_weight_kg' => $yieldKg,
                ]);
            }

            $lot->grapes()->updateOrCreate(
                ['grape_variety' => $variety],
                ['weight_kg' => $yieldKg, 'price_per_kg' => $pricePerKg, 'harvest_entry_id' => $entry->getKey()],
            );

            $vesselId = $data['vessel_id'] ?? $entry->planned_vessel_id;
            if ($vesselId !== null) {
                $vessel = Vessel::query()->whereKey((string) $vesselId)->first();
                if ($vessel !== null) {
                    $this->volumes->assignToVessel($lot, $vessel, $volume);
                }
            }

            $entry->fill([
                'status' => HarvestEntryStatus::Harvested,
                'wine_lot_id' => $lot->getKey(),
                'actual_date' => $data['actual_date'] ?? now(),
                'actual_yield_kg' => $yieldKg,
                'actual_volume_liters' => $volume,
                'grape_price_per_kg' => $pricePerKg,
                'brix' => $data['brix'] ?? $entry->brix,
                'ph' => $data['ph'] ?? $entry->ph,
                'titrable_acidity' => $data['titrable_acidity'] ?? $entry->titrable_acidity,
                'temperature' => $data['temperature'] ?? $entry->temperature,
            ]);
            $entry->save();

            // Roll the delivery into its contract.
            if ($entry->contract_id !== null) {
                $contract = $entry->contract()->lockForUpdate()->first();
                if ($contract !== null) {
                    $contract->delivered_kg = Quantity::add((string) $contract->delivered_kg, $yieldKg);
                    $contract->save();
                }
            }

            return $lot;
        });
    }
}
