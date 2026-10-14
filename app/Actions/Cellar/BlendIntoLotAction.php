<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\CellarTransferType;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\GrapeComposition;
use App\Services\Cellar\LotCostService;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Blend in" on a lot: take wine of another lot from one vessel and add it to
 * this lot in one of its vessels. The lot keeps its identity; its grape
 * percentages are recomputed by volume and it carries the incoming wine's
 * cost (source cost per litre × litres). Drawing from the same vessel the
 * wine goes into needs no extra room. Recorded as a BLEND transfer.
 */
class BlendIntoLotAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly GrapeComposition $grapes,
        private readonly LotCostService $costs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{from_vessel_id: string, volume: float|int|string, to_vessel_id: string, note?: string|null}  $data
     */
    public function execute(WineLot $lot, array $data, string $userId): WineLot
    {
        return DB::transaction(function () use ($lot, $data, $userId): WineLot {
            $volume = Quantity::normalize((string) $data['volume']);
            if (Quantity::compare($volume, '0.000') <= 0) {
                throw ValidationException::withMessages(['volume' => 'Enter the litres to blend in.']);
            }

            $sources = VesselLot::query()
                ->where('vessel_id', $data['from_vessel_id'])
                ->where('wine_lot_id', '!=', $lot->getKey())
                ->get();
            if ($sources->count() !== 1) {
                throw ValidationException::withMessages(['from_vessel_id' => $sources->isEmpty()
                    ? 'That vessel holds no other wine.'
                    : 'That vessel holds several other lots — rack it first.']);
            }
            /** @var VesselLot $source */
            $source = $sources->first();
            if (Quantity::compare($volume, (string) $source->volume) > 0) {
                throw ValidationException::withMessages(['volume' => "That vessel only holds {$source->volume} L of that wine."]);
            }

            if (! $lot->vesselLots()->where('vessel_id', $data['to_vessel_id'])->exists()) {
                throw ValidationException::withMessages(['to_vessel_id' => 'Choose one of this lot\'s vessels.']);
            }

            /** @var WineLot $sourceLot */
            $sourceLot = WineLot::query()->whereKey($source->wine_lot_id)->firstOrFail();
            /** @var Vessel $target */
            $target = Vessel::query()->whereKey($data['to_vessel_id'])->firstOrFail();
            $before = (float) $lot->current_volume;
            $carried = (int) round($this->costs->breakdown($sourceLot)['per_liter'] * (float) $volume);

            $this->volumes->adjust($sourceLot, Quantity::negate($volume), (string) $data['from_vessel_id']);

            $lot->refresh();
            $lot->current_volume = Quantity::add((string) $lot->current_volume, $volume);
            $lot->carried_cost += $carried;
            $lot->save();
            $this->volumes->assignToVessel($lot, $target, $volume);

            $this->grapes->mix($lot, $before, $sourceLot, (float) $volume);

            CellarTransfer::create([
                'from_lot_id' => $sourceLot->getKey(),
                'to_lot_id' => $lot->getKey(),
                'from_vessel_id' => $data['from_vessel_id'],
                'to_vessel_id' => $target->getKey(),
                'created_by_id' => $userId,
                'type' => CellarTransferType::Blend,
                'volume_liters' => $volume,
                'note' => $data['note'] ?? 'Blended in',
            ]);

            $this->audit->record(Auth::user(), 'wine_lot.blended_in', $lot, [
                'from_lot_id' => $sourceLot->getKey(),
                'from_vessel_id' => $data['from_vessel_id'],
                'to_vessel_id' => $target->getKey(),
                'volume' => $volume,
                'carried_cost' => $carried,
            ]);

            return $lot->refresh();
        });
    }
}
