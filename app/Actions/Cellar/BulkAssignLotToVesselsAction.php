<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Map "Assign wine lot" to several selected vessels at once.
 *
 *  - source "existing": place wine the lot already has but no vessel holds
 *    (limited to its unallocated litres);
 *  - source "new": wine arriving now — the lot's current and initial volume grow.
 *  - mode "fill": fill each vessel to capacity, in name order, until the
 *    volume runs out; mode "split": share the volume equally (rounding settled
 *    on the last vessel). Every vessel is capacity-checked.
 *
 * With `dry_run` nothing is written and the per-vessel plan is returned.
 */
class BulkAssignLotToVesselsAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{vessel_ids: list<string>, mode: string, source: string, volume?: float|int|string|null, dry_run?: bool}  $data
     * @return array{lines: list<array{vessel_id: string, vessel_name: string, volume: string}>, total: string, lot_grows_by: string}
     */
    public function execute(WineLot $lot, array $data): array
    {
        return DB::transaction(function () use ($lot, $data): array {
            /** @var WineLot $lot */
            $lot = WineLot::query()->whereKey($lot->getKey())->lockForUpdate()->firstOrFail();
            $vessels = Vessel::query()->whereIn('id', $data['vessel_ids'])->orderBy('name')->get();
            $isNew = $data['source'] === 'new';

            $allocated = Quantity::normalize((string) $lot->vesselLots()->sum('volume'));
            $unallocated = Quantity::sub((string) $lot->current_volume, $allocated);

            $free = $vessels->mapWithKeys(fn (Vessel $v): array => [
                (string) $v->getKey() => Quantity::sub((string) $v->capacity_liters, (string) $v->current_volume),
            ])->all();
            $totalFree = array_reduce($free, fn (string $c, string $f): string => Quantity::add($c, Quantity::isNegative($f) ? '0.000' : $f), '0.000');

            $requested = isset($data['volume']) && $data['volume'] !== ''
                ? Quantity::normalize((string) $data['volume'])
                : ($isNew ? $totalFree : $unallocated);

            if (Quantity::compare($requested, '0.000') <= 0) {
                throw ValidationException::withMessages(['volume' => $isNew ? 'Enter the litres to add.' : 'This lot has no unallocated wine to place.']);
            }
            if (! $isNew && Quantity::compare($requested, $unallocated) > 0) {
                throw ValidationException::withMessages(['volume' => "Only {$unallocated} L of {$lot->name} is not in a vessel."]);
            }

            $plan = $data['mode'] === 'split'
                ? $this->split(array_values($vessels->all()), $requested)
                : $this->fill(array_values($vessels->all()), $free, $requested);

            foreach ($plan as $line) {
                $room = $free[$line['vessel_id']] ?? '0.000';
                if (Quantity::compare($line['volume'], $room) > 0) {
                    throw ValidationException::withMessages(['volume' => "{$line['vessel_name']} has only {$room} L free."]);
                }
            }

            $total = array_reduce($plan, fn (string $c, array $l): string => Quantity::add($c, $l['volume']), '0.000');
            $result = ['lines' => $plan, 'total' => $total, 'lot_grows_by' => $isNew ? $total : '0.000'];

            if (! empty($data['dry_run'])) {
                return $result;
            }

            if ($isNew) {
                $lot->current_volume = Quantity::add((string) $lot->current_volume, $total);
                $lot->initial_volume = Quantity::add((string) $lot->initial_volume, $total);
                $lot->save();
            }

            foreach ($plan as $line) {
                $vessel = $vessels->firstWhere(fn (Vessel $v): bool => (string) $v->getKey() === $line['vessel_id']);
                if ($vessel !== null && Quantity::compare($line['volume'], '0.000') > 0) {
                    $this->volumes->assignToVessel($lot, $vessel, $line['volume']);
                }
            }

            $this->audit->record(Auth::user(), 'wine_lot.assigned_to_vessels', $lot, [
                'source' => $data['source'],
                'mode' => $data['mode'],
                'lines' => $plan,
            ]);

            return $result;
        });
    }

    /**
     * @param  list<Vessel>  $vessels
     * @param  array<string, string>  $free
     * @return list<array{vessel_id: string, vessel_name: string, volume: string}>
     */
    private function fill(array $vessels, array $free, string $remaining): array
    {
        $lines = [];
        foreach ($vessels as $v) {
            $room = $free[(string) $v->getKey()] ?? '0.000';
            $take = Quantity::compare($room, $remaining) < 0 ? $room : $remaining;
            if (Quantity::compare($take, '0.000') > 0) {
                $lines[] = ['vessel_id' => (string) $v->getKey(), 'vessel_name' => $v->name, 'volume' => $take];
                $remaining = Quantity::sub($remaining, $take);
            }
        }

        if (Quantity::compare($remaining, '0.000') > 0) {
            throw ValidationException::withMessages(['volume' => "The selected vessels are {$remaining} L short of room."]);
        }

        return $lines;
    }

    /**
     * @param  list<Vessel>  $vessels
     * @return list<array{vessel_id: string, vessel_name: string, volume: string}>
     */
    private function split(array $vessels, string $total): array
    {
        $n = count($vessels);
        if ($n === 0) {
            return [];
        }

        $share = Quantity::divInt($total, $n);
        $lines = [];
        $given = '0.000';
        foreach ($vessels as $i => $v) {
            $volume = $i === $n - 1 ? Quantity::sub($total, $given) : $share;
            $given = Quantity::add($given, $volume);
            $lines[] = ['vessel_id' => (string) $v->getKey(), 'vessel_name' => $v->name, 'volume' => $volume];
        }

        return $lines;
    }
}
