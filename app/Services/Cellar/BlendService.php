<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\CellarTransferType;
use App\Enums\WineLotStatus;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// VesselVolumeSync is invoked indirectly via LotVolumeService (drain/assign).

/**
 * Multi-source blend: draw volume from several source vessel-lots — each from
 * its own vessel — and mint a new wine lot in a destination vessel (which may
 * be one of the sources: an in-place blend). The new lot:
 *  - is numbered BLEND-YYMMDD[-n] (the prototype's scheme);
 *  - takes the volume-weighted union of the sources' grape composition;
 *  - carries the sources' cost (cost/L × litres taken) — the prototype lost it;
 *  - defaults its name to "Blend {vintage} — A + B", its vintage to the latest
 *    source vintage and its wine type to the sources' shared type.
 * A BLEND transfer is recorded from each source. One transaction, rows locked.
 */
class BlendService
{
    public function __construct(
        private readonly LotNumberGenerator $numbers,
        private readonly LotVolumeService $volumes,
        private readonly LotCostService $costs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {name?, vintage?, wine_type?, destination_vessel_id, sources: [{vessel_lot_id, volume}]}
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
            $distinctLots = collect($sourceLots)->map(fn (array $s): WineLot => $s['lot'])->unique(fn (WineLot $l): string => (string) $l->getKey());
            $vintages = $distinctLots->pluck('vintage')->filter()->sort()->values();
            $vintage = (string) ($data['vintage'] ?? $vintages->last() ?? date('Y'));
            $types = $distinctLots->map(fn (WineLot $l) => $l->wine_type?->value)->filter()->unique();
            $carried = 0;
            foreach ($sourceLots as $s) {
                $carried += (int) round($this->costs->breakdown($s['lot'])['per_liter'] * (float) $s['volume']);
            }

            $name = isset($data['name']) && trim((string) $data['name']) !== ''
                ? (string) $data['name']
                : mb_substr("Blend {$vintage} — ".$distinctLots->pluck('name')->implode(' + '), 0, 160);

            $newLot = WineLot::create([
                'lot_number' => $this->numbers->nextBlend(),
                'name' => $name,
                'grape_variety' => $variety !== '' ? $variety : 'Blend',
                'vintage' => $vintage,
                'wine_type' => $data['wine_type'] ?? ($types->count() === 1 ? $types->first() : null),
                'initial_volume' => $total,
                'current_volume' => $total,
                'carried_cost' => $carried,
                'status' => WineLotStatus::Blended->value,
            ]);

            // Persist the composition as percentages of the total.
            foreach ($varietyVolumes as $grape => $vol) {
                $pct = Quantity::compare($total, '0.000') > 0
                    ? round(((float) $vol / (float) $total) * 100, 2)
                    : null;
                $newLot->grapes()->create(['grape_variety' => $grape, 'percentage' => $pct, 'weight_kg' => null]);
            }

            // Draw each source from its own vessel and record a BLEND transfer.
            foreach ($sourceLots as $s) {
                $this->volumes->adjust($s['lot'], Quantity::negate($s['volume']), (string) $s['vesselLot']->vessel_id);
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

            $this->audit->record(Auth::user(), 'wine_lot.blended', $newLot, [
                'sources' => array_map(fn (array $s): array => [
                    'wine_lot_id' => (string) $s['lot']->getKey(),
                    'vessel_id' => (string) $s['vesselLot']->vessel_id,
                    'volume' => $s['volume'],
                ], $sourceLots),
                'destination_vessel_id' => $destVessel->getKey(),
                'total' => $total,
                'carried_cost' => $carried,
            ]);

            return $newLot;
        });
    }
}
