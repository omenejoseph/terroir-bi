<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Bottling;
use App\Models\CellarTransfer;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Delete a lot. Its vessels are freed, its bottlings are reversed first —
 * so finished-goods stock is taken back out (the prototype left the bottles
 * in stock) — and its transfers are removed. Analyses, additions, processes,
 * tastings and planned work go with it (cascade). A snapshot is audited.
 */
class DeleteWineLotAction
{
    public function __construct(
        private readonly DeleteBottlingAction $deleteBottling,
        private readonly VesselVolumeSync $sync,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(WineLot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            $snapshot = [
                'lot_number' => $lot->lot_number,
                'name' => $lot->name,
                'vintage' => $lot->vintage,
                'current_volume' => (string) $lot->current_volume,
                'vessels' => $lot->vesselLots()->pluck('volume', 'vessel_id')->map(fn ($v): string => (string) $v)->all(),
                'bottlings' => $lot->bottlings()->count(),
            ];

            foreach ($lot->bottlings()->get() as $bottling) {
                /** @var Bottling $bottling */
                $this->deleteBottling->execute($bottling);
            }

            $vesselIds = $lot->vesselLots()->pluck('vessel_id')->all();
            $lot->vesselLots()->delete();
            CellarTransfer::query()
                ->where('from_lot_id', $lot->getKey())
                ->orWhere('to_lot_id', $lot->getKey())
                ->delete();

            $this->audit->record(Auth::user(), 'wine_lot.deleted', $lot, $snapshot);

            $lot->deleteQuietly(); // the richer wine_lot.deleted entry above replaces the model's own
            $this->sync->syncMany($vesselIds);
        });
    }
}
