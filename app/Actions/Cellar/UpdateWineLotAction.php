<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\WineLotStatus;
use App\Models\WineLot;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Support\Facades\DB;

/**
 * Update a wine lot's metadata. Transitioning to BOTTLED frees every vessel the
 * lot occupied (removes its vessel_lots and resyncs those vessels) so emptied
 * tanks don't linger as occupied — the "ghost vessel" fix from the source.
 */
class UpdateWineLotAction
{
    public function __construct(private readonly VesselVolumeSync $sync) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data): WineLot
    {
        return DB::transaction(function () use ($lot, $data): WineLot {
            $lot->fill(array_intersect_key($data, array_flip([
                'name', 'grape_variety', 'vintage', 'vineyard', 'wine_type',
                'status', 'grape_price_per_kg', 'harvest_weight_kg', 'notes',
            ])));

            $becameBottled = isset($data['status'])
                && WineLotStatus::from((string) $data['status']) === WineLotStatus::Bottled;

            $lot->save();

            if ($becameBottled) {
                $vesselIds = $lot->vesselLots()->pluck('vessel_id')->all();
                $lot->vesselLots()->delete();
                $lot->current_volume = '0.000';
                $lot->save();
                $this->sync->syncMany($vesselIds);
            }

            return $lot;
        });
    }
}
