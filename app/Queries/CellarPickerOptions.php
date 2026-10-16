<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\VesselData;
use App\Enums\WineLotStatus;
use App\Models\EnologicalProduct;
use App\Models\Membership;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\VineyardParcel;
use App\Models\WineLot;

/**
 * The pickers cellar screens share — wine lots with the vessels holding them,
 * the vessel map, enological products, team members and vineyard parcels —
 * so every screen lists the same things the same way.
 */
class CellarPickerOptions
{
    /** Lots still in the cellar (not bottled, not blended away). */
    public const ACTIVE_STATUSES = [WineLotStatus::Fermenting, WineLotStatus::Aging, WineLotStatus::Ready];

    /**
     * @return list<array<string, mixed>>
     */
    public function lots(): array
    {
        return array_values(WineLot::query()
            ->with('vesselLots.vessel')
            ->whereIn('status', array_map(fn (WineLotStatus $s): string => $s->value, self::ACTIVE_STATUSES))
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get()
            ->map(fn (WineLot $lot): array => [
                'id' => (string) $lot->getKey(),
                'lot_number' => $lot->lot_number,
                'name' => $lot->name,
                'vintage' => $lot->vintage,
                'grape_variety' => $lot->grape_variety,
                'wine_type' => $lot->wine_type?->value,
                'status' => $lot->status->value,
                'current_volume' => (string) $lot->current_volume,
                'unallocated' => number_format(max(0.0, (float) $lot->current_volume - (float) $lot->vesselLots->sum(fn (VesselLot $vl): float => (float) $vl->volume)), 3, '.', ''),
                'vessels' => array_values($lot->vesselLots
                    ->filter(fn (VesselLot $vl): bool => $vl->vessel !== null)
                    ->sortBy(fn (VesselLot $vl): string => (string) $vl->vessel?->name, SORT_NATURAL | SORT_FLAG_CASE)
                    ->map(fn (VesselLot $vl): array => [
                        'id' => (string) $vl->vessel_id,
                        'name' => (string) $vl->vessel?->name,
                        'room' => $vl->vessel?->room,
                        'volume' => (string) $vl->volume,
                    ])
                    ->all()),
            ])
            ->all());
    }

    /**
     * Active vessels with what they hold, for the map and vessel pickers.
     *
     * @return list<array<string, mixed>>
     */
    public function vessels(): array
    {
        return array_values(Vessel::query()
            ->with('vesselLots.wineLot.latestAnalysis')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Vessel $v): string => $v->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Vessel $v): array => VesselData::fromModel($v, true)->toArray())
            ->values()
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        return array_values(EnologicalProduct::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (EnologicalProduct $p): array => [
                'id' => (string) $p->getKey(),
                'name' => $p->name,
                'category' => $p->category,
                'unit' => $p->unit,
                'current_stock' => (string) $p->current_stock,
                'cost_per_unit' => $p->cost_per_unit?->getMinorAmount(),
                'so2_uplift_per_unit' => $p->so2_uplift_per_unit !== null ? (string) $p->so2_uplift_per_unit : null,
            ])
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function members(): array
    {
        return array_values(Membership::query()
            ->with('user')
            ->get()
            ->map(fn (Membership $m): ?array => $m->user instanceof User
                ? ['value' => (string) $m->user->getKey(), 'label' => $m->user->fullName()]
                : null)
            ->filter()
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * @return list<array{id: string, name: string, grape_variety: string|null, area_hectares: string|null}>
     */
    public function parcels(): array
    {
        return array_values(VineyardParcel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (VineyardParcel $p): array => [
                'id' => (string) $p->getKey(),
                'name' => (string) $p->name,
                'grape_variety' => $p->grape_variety,
                'area_hectares' => $p->area_hectares !== null ? (string) $p->area_hectares : null,
            ])
            ->all());
    }
}
