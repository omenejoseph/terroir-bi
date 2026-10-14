<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CellarTransferType;
use App\Models\Bottling;
use App\Models\CellarTransfer;
use App\Models\HarvestEntry;
use App\Models\VesselLot;
use App\Models\WineLot;

/**
 * Traceability for a lot, vineyard to bottle: the harvest entries (parcel,
 * grower, kg) behind it — following blends back through their source lots —
 * the vessels it has been in, and the bottlings and finished items it went to.
 */
class TraceabilityQuery
{
    private const MAX_DEPTH = 6;

    /**
     * @return array<string, mixed>
     */
    public function get(WineLot $lot): array
    {
        return $this->node($lot, 0, []);
    }

    /**
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function node(WineLot $lot, int $depth, array $seen): array
    {
        $id = (string) $lot->getKey();
        $seen[$id] = true;

        $harvest = HarvestEntry::query()->with(['parcel', 'supplier'])->where('wine_lot_id', $id)->get()->map(fn (HarvestEntry $h): array => [
            'id' => (string) $h->getKey(),
            'date' => $h->actual_date?->toDateString(),
            'grape_variety' => $h->grape_variety,
            'parcel' => $h->parcel !== null ? ['id' => (string) $h->parcel->getKey(), 'name' => $h->parcel->name] : null,
            'grower' => $h->supplier?->company_name,
            'kg' => $h->actual_yield_kg !== null ? (string) $h->actual_yield_kg : null,
        ])->all();

        $sources = [];
        if ($depth < self::MAX_DEPTH) {
            $incoming = CellarTransfer::query()
                ->with('fromLot')
                ->where('to_lot_id', $id)
                ->where('from_lot_id', '!=', $id)
                ->where('type', CellarTransferType::Blend->value)
                ->get();
            foreach ($incoming->groupBy('from_lot_id') as $fromId => $transfers) {
                $from = $transfers->first()?->fromLot;
                if ($from !== null && ! isset($seen[(string) $fromId])) {
                    $sources[] = [
                        'volume' => number_format((float) $transfers->sum(fn (CellarTransfer $t): float => (float) $t->volume_liters), 3, '.', ''),
                        'lot' => $this->node($from, $depth + 1, $seen),
                    ];
                }
            }
        }

        $vessels = collect();
        foreach (CellarTransfer::query()->with(['fromVessel', 'toVessel'])->where('from_lot_id', $id)->orWhere('to_lot_id', $id)->get() as $t) {
            foreach ([[$t->from_lot_id, $t->fromVessel], [$t->to_lot_id, $t->toVessel]] as [$lotId, $vessel]) {
                if ($lotId === $id && $vessel !== null) {
                    $vessels->put((string) $vessel->getKey(), $vessel->name);
                }
            }
        }
        foreach (VesselLot::query()->with('vessel')->where('wine_lot_id', $id)->get() as $vl) {
            if ($vl->vessel !== null) {
                $vessels->put((string) $vl->vessel->getKey(), $vl->vessel->name);
            }
        }

        $bottlings = Bottling::query()->with('inventoryItem')->where('wine_lot_id', $id)->get()->map(fn (Bottling $b): array => [
            'id' => (string) $b->getKey(),
            'date' => $b->date->toDateString(),
            'bottles' => $b->bottle_count,
            'bottle_volume_ml' => $b->bottle_volume_ml,
            'item' => $b->inventoryItem !== null ? ['id' => (string) $b->inventoryItem->getKey(), 'name' => $b->inventoryItem->name, 'sku' => $b->inventoryItem->sku] : null,
        ])->all();

        return [
            'id' => $id,
            'lot_number' => $lot->lot_number,
            'name' => $lot->name,
            'vintage' => $lot->vintage,
            'harvest' => array_values($harvest),
            'blended_from' => $sources,
            'vessels' => $vessels->map(fn (string $name, string $vid): array => ['id' => $vid, 'name' => $name])->values()->all(),
            'bottlings' => array_values($bottlings),
        ];
    }
}
