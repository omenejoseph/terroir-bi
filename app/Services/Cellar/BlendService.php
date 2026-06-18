<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\CellarTransferType;
use App\Enums\WineLotStatus;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// VesselVolumeSync is invoked indirectly via LotVolumeService (drain/assign).

/**
 * Multi-source blend: drain volume from several source vessel-lots and mint a new
 * wine lot in a destination vessel. The new lot's grape composition is the
 * volume-weighted union of the sources', and a BLEND transfer is recorded from
 * each source lot. Runs in one transaction with the touched rows locked.
 */
class BlendService
{
    public function __construct(
        private readonly LotNumberGenerator $numbers,
        private readonly LotVolumeService $volumes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {name, vintage?, wine_type?, destination_vessel_id, sources: [{vessel_lot_id, volume}]}
     */
    public function execute(array $data, string $createdById): WineLot
    {
        /** @var list<array<string, mixed>> $sources */
        $sources = $data['sources'] ?? [];
        if (count($sources) < 2) {
            throw ValidationException::withMessages(['sources' => 'A blend needs at least two sources.']);
        }

        return DB::transaction(function () use ($data, $sources, $createdById): WineLot {
            $destVessel = Vessel::query()->whereKey((string) $data['destination_vessel_id'])->lockForUpdate()->firstOrFail();

            // Tally volume-weighted grape composition + total volume.
            $total = '0.000';
            /** @var array<string, string> $varietyVolumes */
            $varietyVolumes = [];
            $sourceLots = [];

            foreach ($sources as $src) {
                $volume = Quantity::normalize((string) $src['volume']);
                $vesselLot = VesselLot::query()->whereKey((string) $src['vessel_lot_id'])->lockForUpdate()->firstOrFail();
                if (Quantity::compare((string) $vesselLot->volume, $volume) < 0) {
                    throw ValidationException::withMessages(['sources' => 'A source has less volume than requested.']);
                }
                $lot = WineLot::query()->whereKey($vesselLot->wine_lot_id)->lockForUpdate()->firstOrFail();
                $sourceLots[] = ['lot' => $lot, 'vesselLot' => $vesselLot, 'volume' => $volume];

                // Distribute this draw across the source lot's grape varieties.
                $grapes = $lot->grapes()->get();
                if ($grapes->isEmpty()) {
                    $varietyVolumes[$lot->grape_variety] = Quantity::add($varietyVolumes[$lot->grape_variety] ?? '0.000', $volume);
                } else {
                    foreach ($grapes as $g) {
                        $pct = $g->percentage !== null ? (float) $g->percentage : 100.0 / $grapes->count();
                        $part = Quantity::mul($volume, Quantity::normalize((string) ($pct / 100)));
                        $varietyVolumes[$g->grape_variety] = Quantity::add($varietyVolumes[$g->grape_variety] ?? '0.000', $part);
                    }
                }
                $total = Quantity::add($total, $volume);
            }

            $variety = implode(', ', array_keys($varietyVolumes));
            $newLot = WineLot::create([
                'lot_number' => $this->numbers->next(),
                'name' => $data['name'],
                'grape_variety' => $variety !== '' ? $variety : 'Blend',
                'vintage' => (string) ($data['vintage'] ?? date('Y')),
                'wine_type' => $data['wine_type'] ?? null,
                'initial_volume' => $total,
                'current_volume' => $total,
                'status' => WineLotStatus::Blended->value,
            ]);

            // Persist the composition as percentages of the total.
            foreach ($varietyVolumes as $grape => $vol) {
                $pct = Quantity::compare($total, '0.000') > 0
                    ? round(((float) $vol / (float) $total) * 100, 2)
                    : null;
                $newLot->grapes()->create(['grape_variety' => $grape, 'percentage' => $pct, 'weight_kg' => null]);
            }

            // Drain each source and record a BLEND transfer into the new lot.
            foreach ($sourceLots as $s) {
                $this->volumes->drain($s['lot'], $s['volume']);
                CellarTransfer::create([
                    'from_lot_id' => $s['lot']->getKey(),
                    'to_lot_id' => $newLot->getKey(),
                    'from_vessel_id' => $s['vesselLot']->vessel_id,
                    'to_vessel_id' => $destVessel->getKey(),
                    'created_by_id' => $createdById,
                    'type' => CellarTransferType::Blend,
                    'volume_liters' => $s['volume'],
                ]);
            }

            // Place the blended volume into the destination vessel.
            $this->volumes->assignToVessel($newLot, $destVessel, $total);

            return $newLot;
        });
    }
}
