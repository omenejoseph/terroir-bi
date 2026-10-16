<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Bottling;
use App\Models\CellarActivity;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class WineLotData implements Arrayable, JsonSerializable
{
    /**
     * @param  array{total: int, additions: int, grape: int, linked: int, carried: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
     */
    public function __construct(
        public readonly WineLot $lot,
        public readonly bool $withDetail = false,
        public readonly ?array $costBreakdown = null,
    ) {}

    /**
     * @param  array{total: int, additions: int, grape: int, linked: int, carried: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
     */
    public static function fromModel(WineLot $lot, bool $withDetail = false, ?array $costBreakdown = null): self
    {
        return new self($lot, $withDetail, $costBreakdown);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lot = $this->lot;

        $payload = [
            'id' => $lot->getKey(),
            'lot_number' => $lot->lot_number,
            'name' => $lot->name,
            'grape_variety' => $lot->grape_variety,
            'vintage' => $lot->vintage,
            'vineyard' => $lot->vineyard,
            'wine_type' => $lot->wine_type?->value,
            'initial_volume' => (string) $lot->initial_volume,
            'current_volume' => (string) $lot->current_volume,
            'status' => $lot->status->value,
            'fermentation_template_id' => $lot->fermentation_template_id,
            'grape_cost' => $lot->grape_cost?->jsonSerialize(),
            'grape_price_per_kg' => $lot->grape_price_per_kg?->jsonSerialize(),
            'harvest_weight_kg' => $lot->harvest_weight_kg !== null ? (string) $lot->harvest_weight_kg : null,
            'notes' => $lot->notes,
            'latest_analysis' => $this->latestAnalysis(),
            'latest_addition' => $this->latestAddition(),
            'carried_cost' => $lot->carried_cost,
        ];

        // Bottled archive (ListWineLotsQuery with_counts).
        if ($lot->relationLoaded('latestBottling')) {
            $b = $lot->latestBottling;
            $payload['counts'] = [
                'additions' => (int) $lot->getAttribute('additions_count'),
                'analyses' => (int) $lot->getAttribute('analyses_count'),
                'bottlings' => (int) $lot->getAttribute('bottlings_count'),
            ];
            $payload['latest_bottling'] = $b !== null ? [
                'date' => $b->date->toIso8601String(),
                'bottle_count' => $b->bottle_count,
                'bottle_volume_ml' => $b->bottle_volume_ml,
                'inventory_item' => $b->inventoryItem !== null ? ['id' => $b->inventoryItem->getKey(), 'name' => $b->inventoryItem->name] : null,
            ] : null;
        }

        if (! $this->withDetail) {
            return $payload;
        }

        $payload['cost_breakdown'] = $this->costBreakdown;
        $payload['grapes'] = $lot->grapes
            ->map(fn (WineLotGrape $g): array => [
                'id' => $g->getKey(),
                'grape_variety' => $g->grape_variety,
                'percentage' => $g->percentage !== null ? (string) $g->percentage : null,
                'price_per_kg' => $g->price_per_kg?->jsonSerialize(),
                'weight_kg' => $g->weight_kg !== null ? (string) $g->weight_kg : null,
            ])->all();

        $payload['vessels'] = $lot->vesselLots
            ->map(fn (VesselLot $vl): array => [
                'vessel_lot_id' => $vl->getKey(),
                'vessel_id' => $vl->vessel_id,
                'vessel_name' => $vl->relationLoaded('vessel') && $vl->vessel !== null ? $vl->vessel->name : null,
                'volume' => (string) $vl->volume,
            ])->all();

        $payload['analyses'] = ! $lot->relationLoaded('analyses') ? [] : self::newestFirst($lot->analyses, 'date')->map(fn (CellarAnalysis $a): array => LotRows::analysis($a))->all();
        $payload['additions'] = ! $lot->relationLoaded('additions') ? [] : self::newestFirst($lot->additions, 'created_at')->map(fn (CellarAddition $a): array => LotRows::addition($a))->all();
        $payload['processes'] = ! $lot->relationLoaded('processes') ? [] : self::newestFirst($lot->processes, 'date')->map(fn (CellarProcess $p): array => LotRows::process($p))->all();
        $payload['tasting_notes'] = ! $lot->relationLoaded('tastingNotes') ? [] : self::newestFirst($lot->tastingNotes, 'date')->map(fn (CellarTastingNote $n): array => LotRows::tasting($n))->all();
        $payload['transfers'] = ! ($lot->relationLoaded('transfersOut') && $lot->relationLoaded('transfersIn')) ? [] : $lot->transfersOut->merge($lot->transfersIn)->sortByDesc('created_at')->values()
            ->map(fn (CellarTransfer $t): array => LotRows::transfer($t, (string) $lot->getKey()))->all();

        $payload['planned_additions'] = $lot->relationLoaded('plannedAdditions')
            ? $lot->plannedAdditions->map(fn (CellarActivity $a): array => [
                'id' => $a->getKey(),
                'planned_for' => $a->planned_for->toDateString(),
                'product_name' => $a->payload['product_name'] ?? null,
                'quantity' => isset($a->payload['quantity']) ? (string) $a->payload['quantity'] : null,
                'unit' => $a->payload['unit'] ?? null,
                'vessel' => $a->vessel !== null ? ['id' => $a->vessel->getKey(), 'name' => $a->vessel->name] : null,
                'round_id' => $a->round_id,
            ])->all()
            : [];

        $payload['bottlings'] = ! $lot->relationLoaded('bottlings') ? [] : self::newestFirst($lot->bottlings, 'date')->map(fn (Bottling $b): array => LotRows::bottling($b))->all();

        return $payload;
    }

    /**
     * Compact latest-analysis summary for list views (null unless eager-loaded).
     *
     * @return array<string, mixed>|null
     */
    private function latestAnalysis(): ?array
    {
        if (! $this->lot->relationLoaded('latestAnalysis') || $this->lot->latestAnalysis === null) {
            return null;
        }
        $a = $this->lot->latestAnalysis;

        return [
            'date' => $a->date->toIso8601String(),
            'ph' => $a->ph !== null ? (string) $a->ph : null,
            'alcohol' => $a->alcohol !== null ? (string) $a->alcohol : null,
            'free_so2' => $a->free_so2 !== null ? (string) $a->free_so2 : null,
            'total_so2' => $a->total_so2 !== null ? (string) $a->total_so2 : null,
        ];
    }

    /**
     * Compact latest-addition summary for list views (null unless eager-loaded).
     *
     * @return array<string, mixed>|null
     */
    private function latestAddition(): ?array
    {
        if (! $this->lot->relationLoaded('latestAddition') || $this->lot->latestAddition === null) {
            return null;
        }
        $a = $this->lot->latestAddition;

        return [
            'name' => $a->name,
            'quantity' => (string) $a->quantity,
            'unit' => $a->unit,
            'date' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Relations load in no particular order; every list here is newest first (by its own date,
     * then by when it was recorded), so "latest" is always element 0 for the API and the screens.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return Collection<int, TModel>
     */
    private static function newestFirst(Collection $rows, string $column): Collection
    {
        return $rows->sortBy([[$column, 'desc'], ['created_at', 'desc']])->values();
    }
}
