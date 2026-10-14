<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjust a lot's volume (evaporation, loss, top-up, correction) and keep its
 * vessels in sync. With a vessel only that vessel changes, and a removal is
 * capped at what it holds; without one, a lot spread over several vessels
 * changes in proportion to what each holds (rounding settled on the last).
 *
 * Every adjustment leaves a "Loss" / "Gain" process with the reason — the
 * prototype accepted a reason and threw it away, so losses were untraceable.
 */
class AdjustLotVolumeAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly AddCellarProcessAction $processes,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(WineLot $lot, string $delta, ?string $vesselId = null, ?string $reason = null, ?string $userId = null): WineLot
    {
        $delta = Quantity::normalize($delta);

        return DB::transaction(function () use ($lot, $delta, $vesselId, $reason, $userId): WineLot {
            $vesselLots = $lot->vesselLots()->lockForUpdate()->get();

            if ($vesselId !== null) {
                $held = $vesselLots->firstWhere('vessel_id', $vesselId);
                if ($held instanceof VesselLot && Quantity::isNegative($delta)
                    && Quantity::compare(Quantity::negate($delta), (string) $held->volume) > 0) {
                    throw ValidationException::withMessages(['delta' => "That vessel only holds {$held->volume} L of this lot."]);
                }
                $lot = $this->volumes->adjust($lot, $delta, $vesselId);
                $shares = [['vessel_id' => $vesselId, 'delta' => $delta]];
            } elseif ($vesselLots->count() > 1) {
                $shares = $this->proportional(array_values($vesselLots->all()), $delta);
                foreach ($shares as $share) {
                    $lot = $this->volumes->adjust($lot, $share['delta'], $share['vessel_id']);
                }
            } else {
                $lot = $this->volumes->adjust($lot, $delta);
                $shares = [['vessel_id' => $vesselLots->first()?->vessel_id, 'delta' => $delta]];
            }

            if ($userId !== null) {
                foreach ($shares as $share) {
                    $this->processes->execute($lot, [
                        'kind' => Quantity::isNegative($share['delta']) ? 'Loss' : 'Gain',
                        'vessel_id' => $share['vessel_id'],
                        'volume' => Quantity::isNegative($share['delta']) ? Quantity::negate($share['delta']) : $share['delta'],
                        'note' => $reason !== null && $reason !== '' ? $reason : 'Volume adjusted',
                    ], $userId);
                }
            }

            $this->audit->record(Auth::user(), 'wine_lot.volume_adjusted', $lot, [
                'delta' => $delta,
                'vessels' => $shares,
                'reason' => $reason,
                'current_volume' => (string) $lot->current_volume,
            ]);

            return $lot;
        });
    }

    /**
     * @param  list<VesselLot>  $vesselLots
     * @return list<array{vessel_id: string, delta: string}>
     */
    private function proportional(array $vesselLots, string $delta): array
    {
        $total = array_reduce($vesselLots, fn (string $c, VesselLot $vl): string => Quantity::add($c, (string) $vl->volume), '0.000');
        $shares = [];
        $given = '0.000';
        $last = count($vesselLots) - 1;

        foreach ($vesselLots as $i => $vl) {
            $share = $i === $last
                ? Quantity::sub($delta, $given)
                : Quantity::normalize((string) round((float) $delta * (float) $vl->volume / max((float) $total, 0.001), 3));
            $given = Quantity::add($given, $share);
            $shares[] = ['vessel_id' => (string) $vl->vessel_id, 'delta' => $share];
        }

        return $shares;
    }
}
